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

## User pages live on the English wiki

The `user` table is shared, so a contributor is one person family-wide and gets
one profile. A `MediaWikiPerformAction` hook in `wiki/LocalSettings.php` sends
`NS_USER` and `NS_USER_TALK` views on every non-`en` wiki to the English
equivalent — `/it/Utente:X` → `/en/User:X`, `/de/Benutzer_Diskussion:X` →
`/en/User_talk:X` — whether or not a local page exists.

Deliberate exclusions:

- **Subpages stay local.** `User:X/common.js` and `User:X/common.css` are loaded
  per-wiki by ResourceLoader and would break if they redirected; sandboxes and
  drafts belong to the wiki they were written on.
- **Views only.** `action=edit`, `action=history`, `?redirect=no`, `&oldid=` and
  `&diff=` still reach the local page, so the 310 pre-existing non-`en` user
  pages and their attribution history remain reachable — they were left in place,
  not deleted.
- It is a **302**. A 301 would be cached by browsers and by Cloudflare in front
  of us and would be painful to walk back.

Caveat of redirecting `NS_USER_TALK`: a message left on a local wiki's talk page
still triggers that wiki's "you have new messages" bar, but following it lands on
the English talk page. Point people at `/en/User_talk:X` for conversations.

The 6 non-`en` user pages with real content whose author had no English user page
were copied to `en` first (bot edits, source page named in the edit summary for
attribution). 13 more orphans held nothing but `[[categoría:WikiMochilero]]` and
were not copied.

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

## Main pages are templated, not hand-copied

All 34 language wikis' front pages (`Main Page`, `Hauptseite`, `Accueil`, …)
share one structure — header, nav links, newsletter box, Events/News boxes,
gallery, related projects. They used to be 34 independent hand-translated
copies that silently drifted (missing `__NOTOC__`, a stray editorial HTML
comment, five of them on an older layout entirely). Now the structure lives
once in `tools/main_page_template.wikitext` and each language's strings live
in `tools/main_page_i18n/<lang>.json`:

```bash
python3 tools/build_main_pages.py render de        # preview generated wikitext
python3 tools/build_main_pages.py push de           # write it to that wiki
python3 tools/build_main_pages.py push all          # every language wiki
python3 tools/build_main_pages.py extract <lang>    # re-pull strings from a live page
python3 tools/build_main_pages.py check             # has anyone hand-edited one?
```

A structural change (new section, restyle a class) goes in the template once and
`push all` propagates it; a wording change goes in one language's JSON file.

### The pages are not hand-edited on the wiki

A hand edit on a front page is a dead end: it reaches only that one language and
is overwritten by the next `push`. That happened for real on 2026-08-01 — a
gallery photo was dropped from `en` on-wiki and the other 33 wikis never heard
about it. So all 34 are sysop-protected and say where to edit instead:

```bash
python3 tools/protect_main_pages.py status    # protection per wiki
python3 tools/protect_main_pages.py notices   # (re)write the edit notices
python3 tools/protect_main_pages.py protect   # (re)protect the pages
```

Three channels carry the same message, because they reach different people:

- the `<!-- GENERATED PAGE … -->` comment at the top of the template, which
  anyone reading the source sees;
- `MediaWiki:Editnotice-0-<DBkey>` on each wiki, shown to admins on the edit form;
- an `EditPage::showReadOnlyForm:initial` hook in `wiki/LocalSettings.php` that
  repeats that same edit notice on the view-source screen — core renders no edit
  notices there, so without it a non-admin sees nothing.

All three point at **`Hitchwiki:Main page`** on `en`, which documents the routes,
including the one that needs no repo access at all: the Events and News boxes are
ordinary wiki edits at `en:Template:EventsShared` / `en:Template:NewsShared`, and
every wiki's `Template:Events` / `Template:News` just mirrors them via
`{{hwen:…}}`, so they update the whole family live.

`maintenance/edit.php` writes through `PageUpdater`, which does no permission
checks — the protection does not block `push`. A weekly cron runs `check` and
mails a diff if an admin edited one anyway.

`he`, `it`, `ro`, `ru` and `uk` used to have genuinely different, older-style
front pages (their own box layouts, `it`'s own `{{Eventi}}`/`{{Notizie}}`
templates, `ru`'s a plain redirect to `АвтостопВики`) instead of translations
of the standard one. They were brought onto the shared structure — reusing
whichever of their existing links/phrasing already matched a standard field,
translating the rest — so run `extract <lang>` again before hand-editing one
of those five JSON files, in case a manual translation pass improves on what's
there now. `uk` is the one exception that needed no new translation: it
already had the full standard layout, plus two extra community boxes
("Рекомендовані статті", "Приєднайся до команди") that an `extra_sections`
field carried over at first and that were then dropped, on the grounds that a
front page carrying more than the shared structure is the divergence this whole
arrangement exists to prevent. No language has anything bolted on now, and there
is no mechanism for it — a section that belongs on one front page belongs on all
34, so it goes in the template.

## The general-info articles are translated from English, not written per wiki

Every main page's nav bar links to the same introductory set — Top tips, First
time hitchhiking, Hitchhiker's safety, Where to hitchhike, Picking up
hitchhikers, Hitchhiking races, Roles — plus the `Category:General info`
landing page. English owns them; the other 33 wikis get a translation:

```bash
python3 tools/translate_general_pages.py plan            # what is missing where
python3 tools/translate_general_pages.py translate all -j 8
python3 tools/translate_general_pages.py push all
python3 tools/translate_general_pages.py register        # interlanguage links
```

`translate` writes one JSON per page under `tools/general_pages_out/<lang>/` and
is cached, so re-running it costs nothing and `push` is a separate, reviewable
step. Both skip a page that already exists on the target wiki — an article a
human wrote or improved is never overwritten. Use `--force` to redo one
deliberately.

Only prose is sent to the model. Link *targets* are structural and stay in
English (`[[Etiquette]]` becomes `[[Etiquette|Etikette]]`), as do category
names, file names, template and parameter names, and URLs — the English title is
the key that `page_translations` and every cross-wiki link resolve against.
Each translation is checked against its source for exactly that (link, file,
template and URL sets identical, heading count identical, not truncated) and a
page that fails is retried once and then skipped rather than pushed.

The article lands at its translated title (`de:Zum ersten Mal trampen`), with
redirects from the English title and its aliases (`First time hitchhiking`,
`First time`, `Virgin hitchhiking`) so links written anywhere in the family keep
resolving. Where a main page already links to a translated title — `he`, `it`,
`ro`, `ru` — the page is created under exactly that name instead.

`register` rewrites the `page_translations` rows for each concept from what the
wikis actually contain, so the interlanguage sidebar works. Run it after `push`.

The Community Portal is deliberately **not** translated: it is a directory of
English-language external resources, not an informational article.

## Country and city articles are translated from English too

Same principle as the general-info pages, at a different scale. English has
~2,750 place articles (233 countries, 2,517 cities); most other wikis had almost
none, so someone searching in Finnish or Lithuanian found nothing. The gap is
filled from English, in the order a reader is most likely to want:

| tier | what | where |
| --- | --- | --- |
| `countries` | the 62 European countries | the 26 European wikis |
| `concepts` | the articles explaining hitchhiking itself — safety, etiquette, on-ramps, police, borders, winter, money | all 33 wikis |
| `home-cities` | cities in the language's own countries | that wiki |
| `major-cities` | the *N* most linked-to cities plus every national capital (`--top N`, default 150 → 302 cities) | all 33 wikis |

The `concepts` tier comes from the wiki's own `Category:General info`, plus a
few techniques that live outside it (`Petrol station hitchhiking`,
`Official Hitchhiking`, `Hitchhiking Bench`), minus the link directories,
community rosters and news archives — the same reasoning that keeps the
Community Portal in English. Do **not** try to find these by inbound link
count: the motorway navboxes put `A7 (Germany)` on 206 pages and `Etiquette` on
far fewer, so that ranking returns nothing but roads.

```bash
python3 tools/place_corpus.py build                              # index en + coverage
python3 tools/translate_place_articles.py plan --tier all --bytes
python3 tools/translate_place_articles.py batch submit --tier countries
python3 tools/translate_place_articles.py batch poll --watch
python3 tools/translate_place_articles.py batch collect
python3 tools/translate_place_articles.py bootstrap --tier countries   # templates
python3 tools/translate_place_articles.py push --tier countries
python3 tools/translate_place_articles.py register --tier countries
```

Nothing here is hand-maintained per city. `tools/place_corpus.py` derives the
article lists from `Category:Countries` / `Category:Cities`, each city's country
from the `country =` parameter of its English infobox, and each wiki's existing
coverage from `page_translations` plus a direct title check. `HOME_COUNTRIES`
and `EUROPEAN_COUNTRIES` in that file are the only judgement calls, and they are
about languages and geography, not about individual articles.

**A page is only ever created, never overwritten.** If a wiki already has the
article — under the English title or under one `page_translations` knows — it is
left alone, because a human wrote it. All edits are bot-flagged.

Three things make a translated place article differ from its English source:

- **No infobox.** `SharedInfobox` renders the English counterpart's box on it,
  so shipping a translated copy would mean two sources of truth for a population
  figure. The `{{Infobox …}}` call is cut before translation, and `register`
  writes the `page_translations` row that SharedInfobox matches on — so run it
  after every `push` or the article renders with no infobox at all.
- **It ends with `{{hwen:Ai-enhanced}}`**, the "a language model wrote this,
  please verify" banner. Transcluded from `en` over the same interwiki route
  `Template:Events` uses, *not* copied to each wiki: there is one
  `Template:Ai-enhanced` and rewording it reaches all 34 at once. Scary
  transclusion fetches raw wikitext, so the `{{FULLPAGENAME}}` in the banner's
  "correct this article" link still resolves to the local page.
- **It lands at its translated title** (`lt:Albanija`), with a redirect from the
  English title so links written on any wiki keep resolving.

### Templates have to be bootstrapped first

A city article is full of `{{Coords}}`, `{{IsIn}}`, `{{stub}}`, `{{nomadwiki}}`
and motorway shields, and outside `en` (and `de`) none of those templates
existed — a missing template renders as its own literal source in the middle of
the article. `bootstrap` works out the transitive closure of what a tier's
articles actually call, and copies the missing ones over from English. The few
that are mostly a sentence addressed to the reader (`Record-ride`, `Stub`,
`Nomadwiki`, `Coords`) get their prose translated; the rest — shields, navboxes,
`Navbox` itself — are copied verbatim, and a translation that comes back with a
different brace or `{{{1}}}` count is discarded in favour of the English source,
because that is broken code rather than a bad translation. **Run `bootstrap`
before `push`.**

### Use the batch API, not the live one

At ~12,750 articles the OpenAI Batch API is the difference between a long
afternoon and a week: no rate limit to fight and half the price. `batch submit`
chunks a tier into 2,000-article jobs, `batch poll --watch` follows them, and
`batch collect` validates each reply and writes it to `tools/place_pages_out/`.
`translate` does the same thing live and is what to use for the handful that
fail validation, or for a single page.

Only prose is sent to the model. Link *targets*, category and file names,
template names and parameters, and URLs stay byte-identical, so a translation
cannot repoint a link or invent a template and the English title stays the key
everything resolves against. Each reply is checked against its source for
exactly that (link, file, template and URL sets identical, heading count
identical, not truncated) and a page that fails is retried once and then skipped
rather than pushed.

Everything under `tools/place_index.json`, `place_text_cache/`,
`place_pages_out/` and `place_batches/` is derived working state and is
gitignored — the index rebuilds with `place_corpus.py build`, and the rest has
already been pushed to the wikis. Delete it when a run is finished.

## The project namespace is `Hitchwiki:` everywhere

`$wgSitename` differs per wiki (Tramperwiki, Autostopwiki, Liftariwiki,
Otostopviki), and the project namespace used to follow it. That made
`[[Hitchwiki:About]]` and `[[Hitchwiki:Community Portal]]` — which every main
page links to — resolve into the **main** namespace on those seven wikis, where
they could never be found. `$wgMetaNamespace = 'Hitchwiki'` in
`wiki/LocalSettings.php` fixes it family-wide; `$hwOldProjectNamespaces` next to
it keeps the old names (and their localised talk forms) as aliases, so
`[[Tramperwiki:Übersetzung]]` and the existing project pages still resolve.

## Site JavaScript is family-wide, not per-wiki

Do not put shared JavaScript in `MediaWiki:Common.js`. It lives in
`data/hitchwiki-common.js` and is loaded on every wiki as the `hitchwiki.common`
ResourceLoader module (registered in `wiki/LocalSettings.php`). The per-wiki
`MediaWiki:Common.js` pages used to hold 34 copies of it that had drifted into six
versions, 26 of them missing the infobox map code altogether.

Each wiki's `MediaWiki:Common.js` is still loaded on top of the module, so
language-specific JavaScript remains possible — but never paste shared code back
into one. It would then run twice, and the parts that toggle something (the
`Special:Block` checkbox defaults) would toggle straight back off.

## Infobox map tiles are self-hosted

The `<map lat=… lng=… zoom=… />` tag in an infobox is turned into a 3×3 mosaic of
OpenStreetMap raster tiles by `data/hitchwiki-common.js`. The tiles come from our
own origin at `/tiles/{z}/{x}/{y}.png` (`./tiles` bind-mounted read-only into the
container), **not** from `tile.openstreetmap.org` — fetching them per page view
across ~4,500 articles violates the OSM tile usage policy.

`tools/seed_map_tiles.py` derives the needed set from the wikitext and downloads
what is missing; a weekly cron tops it up. A tile that is not cached yet falls back
to OSM once, so a newly added map is never a blank hole.

On top of the mosaic each map draws a clickable pin per well-rated hitchhiking spot,
from `spots/{z}/{x}/{y}.js` (`tools/build_map_spots.py`, rebuilt daily). Note the
`.js` extension on what is really JSON: **Cloudflare fronts the site and answers a
plain `.json` request with a 403 interstitial**, while `.js`/`.css`/`.png` pass as
static assets. Both static directories carry a `.htaccess` with `RewriteEngine Off`,
without which a miss is handed to MediaWiki's rewrite as a 301 into `index.php`
instead of a 404. See `data/README.md`.

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
