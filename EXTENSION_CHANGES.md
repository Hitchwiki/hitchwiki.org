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

Two independent sets of changes. Apply both, in either order.

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
