# CLAUDE.md

## Project Overview

Hitchwiki is a multilingual MediaWiki (1.44.2) wiki family running in Docker. Each language has its own database (`hitchwiki_<lang>`) but shares user tables from the English wiki via `$wgSharedDB`.

## Architecture

- **Docker**: single `hitchwiki-mediawiki` container, config in `docker-compose.yml`
- **MediaWiki config**: `wiki/LocalSettings.php` (bind-mounted into container at `/var/www/html/LocalSettings.php`)
- **Environment**: `.env` file at project root, loaded via `vlucas/phpdotenv`
- **Languages**: ar, bg, cs, da, de, el, en, es, et, fa, fi, fr, he, hr, hu, it, ja, ka, lt, lv, mn, nl, no, pl, pt, ro, ru, sk, sl, sr, sv, tr, uk, zh (defined in `$hwLanguages` in `wiki/LocalSettings.php`)
- **Database per language**: `hitchwiki_<lang>` (e.g. `hitchwiki_en`, `hitchwiki_de`)
- **Shared DB**: `hitchwiki_en` — shares `user`, `user_properties`, `user_autocreate_serial`, `interwiki`, `spoofuser` tables across all wikis
- **Extensions dir**: `extensions/` at project root (bind-mounted or built into image)

## Common Commands

```bash
# Run MediaWiki maintenance scripts
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php <script> --wiki=<lang>

# Run database schema update for a single wiki
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php update --wiki=de --quick

# Run database schema update for ALL wikis (required after upgrades or new extensions)
for lang in en bg de es fi fr he hr nl pl pt ro ru tr zh it lt uk; do
  docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php update --wiki=$lang --quick
done

# Restart the container
docker restart hitchwiki-mediawiki

# Get current config values for a wiki
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php getConfiguration --wiki=<lang> --format=json --settings="wgSharedTables wgSharedDB wgDBname"
```

## Bulk-editing articles

When making programmatic changes to article content (e.g. via `maintenance/edit.php` or the API), always edit as a bot: pass `--bot` (`edit.php`) or `bot=1`/use a bot-flagged account, so the edits are flagged as bot edits rather than cluttering Recent Changes and watchlists like a human edit would.

## Infoboxes live on the English wiki

Articles on the other language wikis do not carry their own `{{Infobox …}}`.
The `SharedInfobox` extension renders the English counterpart's infobox on them
instead, matched through the shared `page_translations` table, so infobox facts
have a single source of truth. Edit the English article to change what every
language shows.

Every language wiki is migrated. What remains are a handful of articles whose
English counterpart does not exist yet (mostly Polish, Russian and Ukrainian
towns); they keep their own infobox until someone writes the English article.
The migration scripts, and the mapping each language needs, are described in
`extensions/SharedInfobox/README.md`.

## Troubleshooting

### Multi-wiki schema updates are critical
Each language wiki has its own database. When adding/upgrading extensions or upgrading MediaWiki, `update.php` must be run for **every** language wiki, not just `en`. Forgetting this causes `DBQueryError` / "Table doesn't exist" errors on non-English wikis.

### Debugging database errors
1. Temporarily add `$wgShowExceptionDetails = true;` to `LocalSettings.php` to see the full SQL error and backtrace
2. Check which table is missing and in which database (e.g. `hitchwiki_de.echo_notification`)
3. Run `update.php --wiki=<lang>` for the affected wiki
4. Remove the debug line after fixing

### File permissions in container
`LocalSettings.php` is bind-mounted read-only from the host. Edit it on the host side, not inside the container.

### LocalSettings.php edits need a container restart
`LocalSettings.php` is bind-mounted as a **single file**, not a directory. Editors that save atomically (vim, most IDEs, Claude Code's Edit tool) replace the file's inode, and the container keeps holding the old inode — so edits appear to have no effect inside the container. After any edit, run `docker restart hitchwiki-mediawiki` and verify with `docker exec hitchwiki-mediawiki grep <your-change> /var/www/html/LocalSettings.php`.

### DB error log
Configured at `/var/log/mediawiki/hitchwiki-db-error.log` inside the container (may not exist if directory wasn't created).
