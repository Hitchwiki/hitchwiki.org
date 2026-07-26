# Extension source changes

This repo pulls extensions in as git submodules and bakes them into the Docker image
(see `Dockerfile`: `COPY extensions/ /var/www/html/extensions/`). Submodule *contents*
are not tracked by the parent repo — only the commit each submodule is pinned to. Any
edit made directly inside `extensions/` therefore disappears on a fresh clone.

This file records those edits so they can be reapplied when the repo is set up from
scratch. Keep it in sync whenever you patch an extension.

As of 2026-07-10 only `extensions/ConfirmAccount` carries local changes. Every other
submodule is clean. To re-check:

```bash
for m in $(git config -f .gitmodules --get-regexp '\.path$' | awk '{print $2}'); do
  d=$(git -C "$m" diff --shortstat 2>/dev/null)
  [ -n "$d" ] && printf "%-40s %s\n" "$m" "$d"
done
```

Note that the "notify only CheckUsers of account requests" behaviour is **not** here —
it lives in `wiki/LocalSettings.php` (`$wgGroupPermissions[...]['confirmaccount-notify']`)
and is tracked normally.

---

## extensions/ConfirmAccount

Pinned at `29b70d97bde3998036cf68cf0dafa4ace462998a`, branch `REL1_44`.

Three independent sets of changes. Apply in any order.

### 1. Hitchwiki custom behaviour

Two things: a hard guard against approving an account whose email address was never
confirmed (1a, below), and silent spam filtering on the account-request form (1b).

#### 1a. Require a confirmed email before an account can be approved

Save the block below to `/tmp/confirmaccount-email-guard.patch` and apply it:

```bash
git -C extensions/ConfirmAccount apply /tmp/confirmaccount-email-guard.patch
```

```diff
diff --git a/includes/business/AccountConfirmSubmission.php b/includes/business/AccountConfirmSubmission.php
--- a/includes/business/AccountConfirmSubmission.php
+++ b/includes/business/AccountConfirmSubmission.php
@@ -198,6 +198,21 @@ class AccountConfirmSubmission {
 	protected function acceptRequest( IContextSource $context ) {
 		global $wgAccountRequestTypes;
 
+		# Hitchwiki: refuse to create an account whose email was never confirmed.
+		# This mirrors the hard guard in ConfirmAccountPreAuthenticationProvider and
+		# just gives the bureaucrat immediate feedback before the CreateAccount form.
+		if ( !$this->accountReq->getEmailAuthTimestamp() ) {
+			return [
+				'accountconf_email_unconfirmed',
+				( new RawMessage(
+					'This account request cannot be approved because the applicant has not ' .
+					'confirmed their email address. Ask them to click the confirmation link ' .
+					'that was emailed to them when they requested the account.'
+				) )->escaped(),
+				null
+			];
+		}
+
 		$id = $this->accountReq->getId();
 		$type = $wgAccountRequestTypes[$this->accountReq->getType()][0];
 
diff --git a/includes/business/ConfirmAccountPreAuthenticationProvider.php b/includes/business/ConfirmAccountPreAuthenticationProvider.php
--- a/includes/business/ConfirmAccountPreAuthenticationProvider.php
+++ b/includes/business/ConfirmAccountPreAuthenticationProvider.php
@@ -59,6 +59,18 @@ class ConfirmAccountPreAuthenticationProvider extends AbstractPreAuthenticationP
 			return StatusValue::newFatal( 'confirmaccount-badid' );
 		}
 
+		# Hitchwiki: never create an account whose email address was not confirmed.
+		# getEmailAuthTimestamp() is null until the applicant clicks the confirmation
+		# link emailed to them at request time (acr_email_authenticated). This blocks
+		# the account creation regardless of how a bureaucrat reached this point.
+		if ( !$accountReq->getEmailAuthTimestamp() ) {
+			return StatusValue::newFatal( new RawMessage(
+				'This account request cannot be approved because the applicant has not ' .
+				'confirmed their email address. Ask them to click the confirmation link ' .
+				'that was emailed to them when they requested the account.'
+			) );
+		}
+
 		/** @var UserDataAuthenticationRequest $usrDataAuthReq */
 		$usrDataAuthReq = AuthenticationRequest::getRequestByClass(
 			$reqs, UserDataAuthenticationRequest::class );
```

`RawMessage` needs no `use` statement: these files declare no namespace and the global
`\RawMessage` alias still exists in MW 1.44.

#### 1b. Silent spam filtering on the account-request form

**The live rules are deliberately not written down here.** This file is committed to a
public repo, and publishing the exact patterns we match on tells spammers precisely how
to word a request that gets through. Only the shape of the change is recorded below; get
the real patch from the private location noted at the bottom of this section.

The change inserts a block into `AccountRequestSubmission.php`, in the `submit()` method,
immediately after the existing duplicate-request checks and immediately *before* this
line:

```php
$hookRunner = new HookRunner( MediaWikiServices::getInstance()->getHookContainer() );
```

Structurally it is a flat sequence of independent guards, each of the following form. A
match closes the open atomic DB section and returns `[ true, null ]` — a *success* value,
so the spammer sees a normal "request submitted" confirmation and gets no feedback about
which rule tripped. Nothing is written to `account_requests`.

```php
// Add custom spam protection here

// <what this rule catches>
if ( /* <PLACEHOLDER: predicate over $this->bio> */ ) {
	$dbw->endAtomic( __METHOD__ );
	return [ true, null ];
}

// ... further $this->bio rules, same shape ...

// <what this rule catches>
if ( /* <PLACEHOLDER: predicate over $this->email> */ ) {
	$dbw->endAtomic( __METHOD__ );
	return [ true, null ];
}

// End custom spam protection
```

There are currently several rules matching on `$this->bio` and one matching on
`$this->email`. Two invariants matter if you ever reimplement this:

- **Always call `$dbw->endAtomic( __METHOD__ )` before returning.** `submit()` has an
  open atomic section at this point; returning without closing it corrupts the
  transaction state for the rest of the request.
- **Return `[ true, null ]`, not an error.** Returning an error would tell the spammer
  their content was rejected and let them iterate against the filter.

The real patch is **not** stored in this repo. It lives in the private
`hitchwiki-private` repo at `patches/confirmaccount-spam-filters.patch` (on the
production host, `/opt/hitchwiki-private/patches/confirmaccount-spam-filters.patch`).
Apply it the same way:

```bash
git -C extensions/ConfirmAccount apply /opt/hitchwiki-private/patches/confirmaccount-spam-filters.patch
```

### 2. Backport of upstream's deprecated-`Xml::` removal

On MW 1.44 the special pages emit `Use of MediaWiki\Xml\Xml::radio was deprecated in
MediaWiki 1.42`. Upstream fixed this on `master`, but never backported it to `REL1_44`.
Do not switch the submodule to `master` — master has moved to PSR-4 autoloading and
namespaced classes and targets MW 1.45.

Instead cherry-pick the two upstream commits onto the pinned REL1_44 tree:

```bash
git -C extensions/ConfirmAccount fetch origin
git -C extensions/ConfirmAccount cherry-pick -n 52d13190 e039b343
git -C extensions/ConfirmAccount reset            # keep as working-tree changes
```

- `52d13190` — Replace use of deprecated `Xml::radio()`
- `e039b343` — Remove deprecated `Xml::` usage from pages

Both apply cleanly. They touch `ConfirmAccount_body.php`, `RequestAccount_body.php` and
`UserCredentials_body.php`.

**Then apply this fix, which is required.** `e039b343` introduces `Html::` calls into
`UserCredentials_body.php` without an import, because on `master` that import had already
been added by an earlier master-only commit. `UserCredentials_body.php` declares no
namespace, so `Html::` resolves to the global `\Html`, which does **not** exist in MW 1.44
(unlike `\Xml`, which does). Without this, `Special:UserCredentials` fatals as soon as
account "areas" are configured:

```diff
--- a/includes/frontend/specialpages/actions/UserCredentials_body.php
+++ b/includes/frontend/specialpages/actions/UserCredentials_body.php
@@ -1,5 +1,6 @@
 <?php
 
+use MediaWiki\Html\Html;
 use MediaWiki\User\UserGroupManager;
 use MediaWiki\User\UserIdentityLookup;
 use Wikimedia\Rdbms\ILoadBalancer;
```

This whole section becomes unnecessary once the submodule is moved to `REL1_45` or later,
which will contain both commits. Drop it at that point rather than carrying it forward.

### 3. Email reminder for unconfirmed account requests

A request cannot be approved until its email is confirmed (guard 1a above), but many
applicants miss the single confirmation mail sent at signup. Nothing in MediaWiki core or
any extension resends it for a *pending* request — core's `Special:ConfirmEmail` only
works for already-registered users, and at this stage there is only a row in
`account_requests` with no user account. So we built a reminder.

A cron job (`tools/send_email_reminders.sh`, tracked in the parent repo, `*/15` in the
hitchwiki crontab) runs the new maintenance script for every language wiki. The script
resends the confirmation mail **once** per request, no earlier than 1h after signup, to
addresses still unconfirmed. Idempotency is via a new nullable column
`acr_email_reminded` (stamped after a successful send), so running it often is safe.

Because the original plaintext token is never stored (only its md5), each reminder mints
a **fresh** token and rewrites the stored hash + expiry so the new link validates.

Save the block below to `/tmp/confirmaccount-email-reminder.patch` and apply it:

```bash
git -C extensions/ConfirmAccount apply /tmp/confirmaccount-email-reminder.patch
```

```diff
diff --git a/i18n/requestaccount/en.json b/i18n/requestaccount/en.json
--- a/i18n/requestaccount/en.json
+++ b/i18n/requestaccount/en.json
@@ -43,6 +43,8 @@
 	"requestaccount-econf": "Your email address has been confirmed and will be listed as such in your account request.",
 	"requestaccount-email-subj": "{{SITENAME}} email address confirmation",
 	"requestaccount-email-body": "Someone, probably you from IP address $1, has requested an account \"$2\" with this email address on {{SITENAME}}.\n\nTo confirm that this account really does belong to you on {{SITENAME}}, open this link in your browser:\n\n$3\n\nIf the account is created, only you will be emailed the password.\nIf this is *not* you, do not follow the link.\nThis confirmation code will expire at $4.",
+	"requestaccount-email-subj-reminder": "Reminder: confirm your email address for {{SITENAME}}",
+	"requestaccount-email-body-reminder": "You requested an account \"$2\" on {{SITENAME}} (from IP address $1), but your email address has not been confirmed yet.\n\nUntil you confirm it, your account request cannot be approved. To confirm your email address, open this link in your browser:\n\n$3\n\nIf this was *not* you, you can ignore this email.\nThis confirmation code will expire at $4.",
 	"requestaccount-email-subj-admin": "{{SITENAME}} account request",
 	"requestaccount-email-body-admin": "$1 has requested an account and is waiting for confirmation.\nThe email address has been confirmed. You can confirm the request here:\n\n$2",
 	"acct_request_throttle_hit": "Sorry, you have already requested {{PLURAL:$1|1 account|$1 accounts}}.\nYou cannot make any more requests."
diff --git a/i18n/requestaccount/qqq.json b/i18n/requestaccount/qqq.json
--- a/i18n/requestaccount/qqq.json
+++ b/i18n/requestaccount/qqq.json
@@ -56,6 +56,8 @@
 	"requestaccount-econf": "Used as success message.\n\nThis message is followed by a link which points to the Main page.",
 	"requestaccount-email-subj": "{{Identical|SITENAME e-mail address confirmation}}",
 	"requestaccount-email-body": "This text is sent in an email. Parameters:\n* $1 - an IP address\n* $2 - a requested user name (no GENDER support)\n* $3 - a URL\n* $4 - a date/time\n* $5 - (Optional) a date\n* $6 - (Optional) a time",
+	"requestaccount-email-subj-reminder": "Subject line of the reminder email sent when an account request's email address was not confirmed within an hour of signup.",
+	"requestaccount-email-body-reminder": "Body of the reminder email sent when an account request's email address is still unconfirmed. Parameters:\n* $1 - an IP address\n* $2 - a requested user name (no GENDER support)\n* $3 - a URL\n* $4 - a date/time",
 	"requestaccount-email-subj-admin": "{{Identical|SITENAME account request}}",
 	"requestaccount-email-body-admin": "This message is the email body text send to a site admin whenever someone has requested a new account.\nMore parameters can be added by adjusting $wgConfirmAdminEmailExtraFields.\n\nParameters:\n* $1 - username\n* $2 - URL",
 	"acct_request_throttle_hit": "Used as error message. Parameters:\n* $1 - number of accounts. value of <code>$wgAccountRequestThrottle</code>."
diff --git a/includes/backend/ConfirmAccount.class.php b/includes/backend/ConfirmAccount.class.php
--- a/includes/backend/ConfirmAccount.class.php
+++ b/includes/backend/ConfirmAccount.class.php
@@ -126,6 +126,34 @@ class ConfirmAccount {
 		);
 	}
 
+	/**
+	 * Send a reminder email confirmation mail for a request whose address was
+	 * never confirmed. The caller supplies a (freshly minted) token whose hash
+	 * has already been persisted, since the original plaintext token is never
+	 * stored and cannot be recovered.
+	 *
+	 * @param User $user
+	 * @param string $ip User IP address
+	 * @param string $token
+	 * @param string $expiration
+	 * @return true|Status True on success, a Status object on failure.
+	 */
+	public static function sendConfirmationReminderMail( User $user, $ip, $token, $expiration ) {
+		$url = self::confirmationTokenUrl( $token );
+		$lang = MediaWikiServices::getInstance()->getUserOptionsManager()
+			->getOption( $user, 'language' );
+		return $user->sendMail(
+			wfMessage( 'requestaccount-email-subj-reminder' )->inLanguage( $lang )->text(),
+			wfMessage( 'requestaccount-email-body-reminder',
+				$ip,
+				$user->getName(),
+				$url,
+				MediaWikiServices::getInstance()->getContentLanguage()
+					->timeanddate( $expiration, false )
+			)->inLanguage( $lang )->text()
+		);
+	}
+
 	/**
 	 * Get request information from an email confirmation token
 	 *
diff --git a/includes/backend/schema/ConfirmAccountUpdater.hooks.php b/includes/backend/schema/ConfirmAccountUpdater.hooks.php
--- a/includes/backend/schema/ConfirmAccountUpdater.hooks.php
+++ b/includes/backend/schema/ConfirmAccountUpdater.hooks.php
@@ -29,6 +29,9 @@ class ConfirmAccountUpdaterHooks implements
 			}
 			$updater->addExtensionIndex( 'account_requests', 'acr_email', "$base/patch-email-index.sql" );
 			$updater->addExtensionField( 'account_requests', 'acr_agent', "$base/patch-acr_agent.sql" );
+			$updater->addExtensionField(
+				'account_requests', 'acr_email_reminded', "$base/patch-acr_email_reminded.sql"
+			);
 			$updater->dropExtensionIndex(
 				'account_requests', 'acr_deleted_reg', "$base/patch-drop-acr_deleted_reg-index.sql"
 			);
diff --git a/includes/backend/schema/mysql/ConfirmAccount.sql b/includes/backend/schema/mysql/ConfirmAccount.sql
--- a/includes/backend/schema/mysql/ConfirmAccount.sql
+++ b/includes/backend/schema/mysql/ConfirmAccount.sql
@@ -27,6 +27,9 @@ CREATE TABLE IF NOT EXISTS /*_*/account_requests (
 	acr_email_token binary(32),
 	-- Expiration date for the user_email_token
 	acr_email_token_expires varbinary(14),
+	-- Timestamp a reminder mail was sent when the email was left unconfirmed;
+	-- NULL until reminded, keeps the reminder maintenance script idempotent.
+	acr_email_reminded varbinary(14) default NULL,
 	-- A little about this user
 	acr_bio mediumblob NOT NULL,
 	-- Private info for reviewers to look at when considering request
diff --git a/includes/backend/schema/mysql/patch-acr_email_reminded.sql b/includes/backend/schema/mysql/patch-acr_email_reminded.sql
new file mode 100644
--- /dev/null
+++ b/includes/backend/schema/mysql/patch-acr_email_reminded.sql
@@ -0,0 +1,5 @@
+-- Adds a marker for the "unconfirmed email" reminder mail.
+-- NULL until a reminder has been sent; set to the send timestamp afterwards
+-- so the reminder maintenance script stays idempotent.
+
+ALTER TABLE /*_*/account_requests ADD acr_email_reminded varbinary(14) default NULL;
diff --git a/maintenance/sendEmailReminders.php b/maintenance/sendEmailReminders.php
new file mode 100644
--- /dev/null
+++ b/maintenance/sendEmailReminders.php
@@ -0,0 +1,131 @@
+<?php
+/**
+ * Send a reminder email to people who requested an account but never confirmed
+ * their email address. Intended to be run periodically from cron.
+ *
+ * A request cannot be approved until its email is confirmed, and many users
+ * miss the original confirmation mail. This resends it once, after the address
+ * has been left unconfirmed for a while (1 hour by default).
+ *
+ * Each reminder mints a fresh confirmation token (the original plaintext token
+ * is not stored, only its hash), updates the stored hash + expiry so the new
+ * link validates, and stamps acr_email_reminded so the reminder is sent only
+ * once per request.
+ */
+
+$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
+require_once "$IP/maintenance/Maintenance.php";
+
+use MediaWiki\MediaWikiServices;
+use MediaWiki\User\User;
+
+class SendAccountRequestEmailReminders extends Maintenance {
+
+	public function __construct() {
+		parent::__construct();
+		$this->requireExtension( 'Confirm User Accounts' );
+		$this->addDescription(
+			'Resend the email-confirmation mail to account requests whose address ' .
+			'has been left unconfirmed for a while (once per request).'
+		);
+		$this->addOption( 'age',
+			'Minimum age in seconds since signup before reminding (default 3600 = 1 hour).',
+			false, true );
+		$this->addOption( 'dry-run', 'List who would be reminded without sending or writing anything.' );
+		$this->setBatchSize( 50 );
+	}
+
+	public function execute() {
+		global $wgEnableEmail, $wgConfirmAccountRejectAge;
+
+		if ( !$wgEnableEmail ) {
+			$this->fatalError( 'Email is disabled ($wgEnableEmail = false); cannot send reminders.' );
+		}
+
+		$minAge = (int)$this->getOption( 'age', 3600 );
+		$dryRun = $this->hasOption( 'dry-run' );
+
+		$dbw = $this->getDB( DB_PRIMARY );
+		$now = time();
+		// Only consider requests old enough to remind, but not so old that they
+		// are already past the rejection age (and about to be purged / rejected).
+		$olderThan = $dbw->timestamp( $now - $minAge );
+		$rejectCutoff = $dbw->timestamp( $now - $wgConfirmAccountRejectAge );
+
+		$res = $dbw->newSelectQueryBuilder()
+			->select( [ 'acr_id', 'acr_name', 'acr_email' ] )
+			->from( 'account_requests' )
+			->where( [
+				'acr_email_authenticated' => null, // not confirmed
+				'acr_deleted' => 0,                // not rejected
+				'acr_email_reminded' => null,      // not already reminded
+				'acr_email != ' . $dbw->addQuotes( '' ),
+				'acr_registration <= ' . $dbw->addQuotes( $olderThan ),
+				'acr_registration >= ' . $dbw->addQuotes( $rejectCutoff ),
+			] )
+			->orderBy( 'acr_id' )
+			->caller( __METHOD__ )
+			->fetchResultSet();
+
+		$sent = 0;
+		$failed = 0;
+		foreach ( $res as $row ) {
+			$user = User::newFromName( $row->acr_name, 'creatable' );
+			if ( !$user ) {
+				$this->error( "Skipping request {$row->acr_id}: invalid username '{$row->acr_name}'." );
+				continue;
+			}
+			$user->setEmail( $row->acr_email );
+
+			if ( $dryRun ) {
+				$this->output( "[dry-run] would remind {$row->acr_name} <{$row->acr_email}> (req {$row->acr_id})\n" );
+				$sent++;
+				continue;
+			}
+
+			// Mint a fresh token and persist its hash + expiry BEFORE sending,
+			// so the link in the mail validates. The original plaintext token
+			// was never stored, so we cannot reuse it.
+			$expiration = null; // set by reference
+			$token = ConfirmAccount::getConfirmationToken( $user, $expiration );
+			$dbw->newUpdateQueryBuilder()
+				->update( 'account_requests' )
+				->set( [
+					'acr_email_token' => md5( $token ),
+					'acr_email_token_expires' => $dbw->timestamp( $expiration ),
+				] )
+				->where( [ 'acr_id' => $row->acr_id ] )
+				->caller( __METHOD__ )
+				->execute();
+
+			$result = ConfirmAccount::sendConfirmationReminderMail(
+				$user, '', $token, $expiration );
+
+			if ( $result === true || ( is_object( $result ) && $result->isOK() ) ) {
+				// Mark reminded only after a successful send.
+				$dbw->newUpdateQueryBuilder()
+					->update( 'account_requests' )
+					->set( [ 'acr_email_reminded' => $dbw->timestamp( $now ) ] )
+					->where( [ 'acr_id' => $row->acr_id ] )
+					->caller( __METHOD__ )
+					->execute();
+				$this->output( "Reminded {$row->acr_name} <{$row->acr_email}> (req {$row->acr_id})\n" );
+				$sent++;
+			} else {
+				$msg = is_object( $result ) ? $result->getWikiText() : 'unknown error';
+				$this->error( "Failed to email {$row->acr_name} (req {$row->acr_id}): $msg" );
+				$failed++;
+			}
+
+			if ( $sent % $this->getBatchSize() === 0 ) {
+				$this->waitForReplication();
+			}
+		}
+
+		$verb = $dryRun ? 'would remind' : 'reminded';
+		$this->output( "Done: $verb $sent request(s)" . ( $failed ? ", $failed failed" : '' ) . ".\n" );
+	}
+}
+
+$maintClass = SendAccountRequestEmailReminders::class;
+require_once RUN_MAINTENANCE_IF_MAIN;
```

After applying, run `update.php` on **every** language wiki to add the `acr_email_reminded`
column (it is per-wiki, not shared), then confirm the cron wrapper `tools/send_email_reminders.sh`
is present and scheduled.

### 4. Unicode-aware biography word count

`AccountRequestSubmission.php`'s minimum-word-count check for the biography field used
PHP's `str_word_count()`, which only recognizes ASCII letters (`a-z`, `A-Z`, plus `'`/`-`
inside a word). It silently counts 0 words for a biography written in Cyrillic, Greek,
CJK, or any other non-Latin script, so those applicants could never clear the
`$wgConfirmAccountRequestFormItems['Biography']['minWords']` threshold (20, set in
`wiki/LocalSettings.php`) no matter how long their bio actually was.

Save the block below to `/tmp/confirmaccount-unicode-wordcount.patch` and apply it:

```bash
git -C extensions/ConfirmAccount apply /tmp/confirmaccount-unicode-wordcount.patch
```

```diff
diff --git a/includes/business/AccountRequestSubmission.php b/includes/business/AccountRequestSubmission.php
--- a/includes/business/AccountRequestSubmission.php
+++ b/includes/business/AccountRequestSubmission.php
@@ -144,7 +144,10 @@
 		}
 		# Check if biography is long enough
-		if ( $formConfig['Biography']['enabled']
-			&& str_word_count( $this->bio ) < $formConfig['Biography']['minWords'] ) {
+		# str_word_count() only recognizes ASCII letters, so it undercounts
+		# biographies written in Cyrillic or other non-Latin scripts; count
+		# words as whitespace-separated runs of letters/numbers instead.
+		$bioWordCount = preg_match_all( '/[\p{L}\p{N}]+/u', $this->bio );
+		if ( $formConfig['Biography']['enabled']
+			&& $bioWordCount < $formConfig['Biography']['minWords'] ) {
 			$minWords = $formConfig['Biography']['minWords'];
 
 			return [
```

### Rebuild and verify

Extensions are baked into the image, so a restart is not enough:

```bash
docker compose up -d --build
```

Sanity checks:

```bash
# no deprecated Xml:: calls remain
docker exec hitchwiki-mediawiki grep -rn "Xml::" \
  /var/www/html/extensions/ConfirmAccount/includes/ || echo "clean"

# global \Html does not exist on 1.44 — every file using Html:: must import it
docker exec hitchwiki-mediawiki grep -L "use MediaWiki.Html.Html" \
  $(docker exec hitchwiki-mediawiki grep -rl "Html::" \
    /var/www/html/extensions/ConfirmAccount/includes/)
```

Then load `Special:ConfirmAccounts/authors?acrid=<id>` as a user holding `confirmaccount`
and confirm the four radios render with `value="accept"`, `"reject"`, `"hold"`, `"spam"`.
`Html::radio()` takes its value from `$attribs['value']`, not a positional argument, so a
botched port silently emits `value="1"` on all four and breaks account approval.
