# CentralLangLinks

Centralised interlanguage links for the Hitchwiki language family.

Instead of every page carrying hand-written `[[xx:Title]]` wikitext — which is
O(N²) to maintain and drifts out of sync — a single **shared** table
`page_translations` maps a *concept* to its page title in every language. The
concept is keyed by its **English page title**, which is the single source of
truth. The core `LanguageLinks` hook renders the sidebar from that table.

Because it maps explicit titles (not identical titles), it handles translated
titles such as `en:Dresden` ↔ `fr:Dresde`, which same-title schemes (e.g.
Cognate) cannot.

## How it works

- `page_translations` is registered in `$wgSharedTables`, so it lives once in
  the shared (English) database and every language wiki reads it through its
  normal DB connection.
- On each page view the `LanguageLinks` hook looks up the current
  `(wiki-language, page-title)` to find its concept, then emits the sibling
  languages' links, replacing whatever the wikitext defined.
- A page with **no** central entry is left untouched, so migration is
  incremental.

## Install

`page_translations` is a shared table. MediaWiki's updater deliberately skips
shared tables (`"...skipping update to shared table"`), so it must be created
**once, directly in the shared database**, exactly like `interwiki`:

```bash
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php \
  sql --wiki=en --query="CREATE TABLE page_translations ( \
    pt_concept VARBINARY(255) NOT NULL, \
    pt_lang VARBINARY(35) NOT NULL, \
    pt_title VARBINARY(255) NOT NULL, \
    PRIMARY KEY (pt_concept, pt_lang), \
    KEY pt_lang_title (pt_lang, pt_title));"
```

(The `sql/page_translations.sql` schema and the `LoadExtensionSchemaUpdates`
hook are kept for correctness / non-shared installs, but are a no-op here.)

## Managing translations

Set the full translation set for one concept (idempotent — replaces the
concept's rows). Always run against the shared wiki, `--wiki=en`:

```bash
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php \
  /var/www/html/extensions/CentralLangLinks/maintenance/setTranslations.php \
  --wiki=en --concept Dresden \
  --link de:Dresden --link fr:Dresde --link tr:Dresden
```

The English concept row is added automatically; pass the other languages as
`--link lang:Title` pairs.

## Seeding from existing langlinks

`seedFromLangLinks.php` bootstraps the whole table from the per-wiki `langlinks`
that already exist. It reads every family wiki's langlinks, treats each link as
an edge between `(language, title)` nodes, and computes connected components
(union-find). Each component is one concept, keyed by its English title (or a
deterministic fallback when no English page exists). Because it unions in every
direction, today's asymmetric/one-way links are healed automatically.

Dry run (default) prints statistics and sample concepts; `--save` performs a
full rebuild (deletes all rows, then inserts):

```bash
# Preview
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php \
  /var/www/html/extensions/CentralLangLinks/maintenance/seedFromLangLinks.php --wiki=en

# Write
docker exec hitchwiki-mediawiki php /var/www/html/maintenance/run.php \
  /var/www/html/extensions/CentralLangLinks/maintenance/seedFromLangLinks.php --wiki=en --save
```

It reports concepts without an English page, per-language title conflicts (first
title kept), and dropped concept-key collisions — review these before `--save`.

## Status

Prototype (v0.1.0), proven on the `Dresden` concept. Seed script validated in dry
run (~1,620 concepts from ~7,400 langlinks). Not yet written family-wide. Once
seeded, a background `edit.php` pass can strip the now-dead `[[xx:]]` wikitext.
