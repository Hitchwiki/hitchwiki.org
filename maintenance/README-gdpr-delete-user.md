# GDPR user erasure — `gdpr-delete-user.php`

Anonymises a user for a GDPR "right to erasure" request: reassigns all of
their contributions to a sink account (**`DeletedUser`**), deletes their
`User:` and `User_talk:` pages, and then deletes the original account
(username, email, real name, password hash).

We do **not** `DELETE` the row directly — that orphans revisions, logs and the
`actor` table and breaks page histories. Anonymising is the GDPR-compliant
approach (the person is de-linked, the content stays attributable).

## Why it must run on every wiki

- The **`user` table is shared** (lives in `hitchwiki_en` via `$wgSharedDB`) —
  the account exists once.
- **Contributions are per-wiki** — `actor`, `revision`, `logging` etc. live in
  each `hitchwiki_<lang>` database. They must be reassigned on every wiki the
  person edited *before* the shared account is deleted, otherwise other wikis
  are left with orphaned `actor` rows still carrying the old username.

## The script lives in the repo, not the container

`extensions/` is baked into the image but this `maintenance/` dir is not, so
copy the script into the running container first:

```bash
docker cp maintenance/gdpr-delete-user.php hitchwiki-mediawiki:/tmp/gdpr-delete-user.php
```

## 1. Dry run first (recommended)

Shows the account's id, email, real name and edit count — changes nothing:

```bash
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php \
  /tmp/gdpr-delete-user.php --wiki=en --olduser="Name To Erase" --dry-run
```

## 2. Reassign contributions on every wiki (`--merge-only`)

```bash
for lang in en bg de es fi fr he hr nl pl pt ro ru tr zh it lt uk; do
  docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php \
    /tmp/gdpr-delete-user.php --wiki=$lang --olduser="Name To Erase" --merge-only
done
```

## 3. Delete the shared account once

Run **without** `--merge-only` (any remaining merge on `en` is a harmless no-op,
then the account is deleted and the user's pages on `en` are moved):

```bash
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php \
  /tmp/gdpr-delete-user.php --wiki=en --olduser="Name To Erase"
```

## 4. Verify the PII is gone

```bash
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php sql --wiki=en \
  --query="SELECT user_id,user_name,user_email,user_real_name FROM user WHERE user_name='Name To Erase'"
```

Should return **zero rows**.

## Options

| Option         | Meaning                                                        |
|----------------|---------------------------------------------------------------|
| `--olduser`    | (required) username to erase                                  |
| `--newuser`    | sink account to reassign edits to (default: `DeletedUser`)    |
| `--merge-only` | reassign contributions but do not delete the account         |
| `--dry-run`    | report what would happen, change nothing                      |

## Don't forget the side-channels

These are **not** handled by this script:

- **Archived page content.** Deleting `User:`/`User_talk:` pages is a normal
  delete — the old revisions move to the `archive` table and remain
  recoverable by admins via `Special:Undelete`. For a hard GDPR erasure,
  suppress them (`Special:RevisionDelete` with the suppress/oversight bit) or
  purge the archive rows for that title. The reassigned contributions are
  *intentionally* kept (now attributed to `DeletedUser`).
- **CheckUser** stores IP addresses (`cu_changes`/`cu_log`) per wiki. They
  auto-prune after `$wgCUDMaxAge` (default ~90 days); prune explicitly if the
  request requires immediate removal.
- **PII inside page content or edit summaries** (e.g. the person wrote their
  real name on a page) needs RevisionDelete, and full removal from history
  needs Oversight/Suppression (`suppressrevision` right).
