# Unsubscribe

A tiny MediaWiki extension exposing **Special:Unsubscribe** — a one-click,
RFC 8058 newsletter unsubscribe endpoint for Hitchwiki's monthly newsletter.

## How it works

The newsletter email carries a signed link / header:

```
https://hitchwiki.org/en/Special:Unsubscribe?u=<userId>&t=<hmac>
```

where

```
<hmac> = HMAC-SHA256( $wgUnsubscribeSecret, userId . email )
```

The HMAC is unguessable, so nobody can unsubscribe a stranger by editing the
URL. The same secret lives in both `LocalSettings.php` (as `$wgUnsubscribeSecret`)
and the newsletter sender.

- **GET** → renders a "Click to unsubscribe" confirmation form for a human who
  clicks the footer link.
- **POST** → verifies the HMAC, sets the user option `hw-newsletter-monthly` to
  `0`, returns `200`. This is the path Gmail / Outlook's one-click button hits
  (`List-Unsubscribe-Post: List-Unsubscribe=One-Click`).

CSRF protection is deliberately **not** used: the HMAC token authenticates the
request, which is the RFC 8058-correct approach (the mailbox provider POSTs
directly, with no session to fetch an edit token from).

### Source of truth

The endpoint flips the exact user option behind
`Special:Preferences#mw-prefsection-personal-newsletter`
(`hw-newsletter-monthly`, stored in `user_properties`). Because `user_properties`
is a Hitchwiki shared table (`$wgSharedTables`), one unsubscribe propagates to
**every** language wiki. Since the SparkPost recipient list is rebuilt from that
same option, the opt-out takes effect on the next send.

We write an explicit `0` rather than deleting the row, so the default-on value
(`$wgDefaultUserOptions['hw-newsletter-monthly'] = 1`) cannot silently
re-subscribe the user.

## Install

`extensions/` is baked into the Docker image (`COPY extensions/` in the
`Dockerfile`), so installing means rebuilding the image — there is no live
bind-mount for extensions.

1. Put the secret in `.env` (gitignored; a placeholder is in `example.env`).
   `docker-compose.yml` injects `.env` into the container via `env_file:`, so
   it is available to PHP as `$_ENV`:

   ```
   # Generate with: php -r 'echo bin2hex(random_bytes(32)) . "\n";'
   UNSUBSCRIBE_SECRET="..."
   ```

   The **same** value must be given to the newsletter sender.

2. Load the extension and read the secret in `wiki/LocalSettings.php` (this is
   the per-secret convention already used for `OAUTH_SECRET_KEY` etc.; note
   `wiki/PrivateSettings.php` is **not** mounted into the container, so the
   secret cannot live there):

   ```php
   wfLoadExtension( 'Unsubscribe' );
   $wgUnsubscribeSecret = $_ENV['UNSUBSCRIBE_SECRET'] ?? '';
   ```

3. Rebuild the image (extensions are baked in) and **recreate** the container so
   the new image, the freshly-mounted `LocalSettings.php`, and the new `.env`
   var are all picked up. A plain `docker restart` will NOT re-read `env_file`:

   ```bash
   docker compose build mediawiki
   docker compose up -d mediawiki
   ```

No `update.php` / schema change is needed — the extension only writes to the
existing `user_properties` table.

### Verify

```bash
# 403 with a bogus token
curl -i 'https://hitchwiki.org/en/Special:Unsubscribe?u=1&t=deadbeef'

# One-click POST with a correctly computed token → 200, and the option flips
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php \
  userOptions --wiki=en --usename <name> hw-newsletter-monthly
```

## Newsletter sender wiring (external)

The sender is not in this repo. In its per-recipient loop, compute the same HMAC
and add the headers:

```python
import hashlib, hmac

SECRET = os.environ["UNSUBSCRIBE_SECRET"].encode()   # same value as $wgUnsubscribeSecret

token = hmac.new(SECRET, f"{user_id}{email}".encode(), hashlib.sha256).hexdigest()
unsub = f"https://hitchwiki.org/en/Special:Unsubscribe?u={user_id}&t={token}"

payload["content"]["headers"] = {
    "List-Unsubscribe": f"<{unsub}>",
    "List-Unsubscribe-Post": "List-Unsubscribe=One-Click",
}
```

This needs the wiki `user_id` per recipient. The recipient list (users with the
newsletter option on) should select it alongside the email. Note the default-on
subtlety: users who have never toggled the preference have **no** row in
`user_properties`, yet are subscribed by default — so the rebuild query must
include them, e.g.:

```sql
-- run against the shared DB (hitchwiki_en)
SELECT u.user_id, u.user_email
FROM   user u
LEFT JOIN user_properties p
       ON p.up_user = u.user_id
      AND p.up_property = 'hw-newsletter-monthly'
WHERE  u.user_email <> ''
  AND  u.user_email_authenticated IS NOT NULL
  AND  COALESCE(p.up_value, '1') <> '0';   -- default-on; only explicit '0' opts out
```

A query that selects only `up_value = '1'` rows would miss every default-on user
who never visited their preferences.
