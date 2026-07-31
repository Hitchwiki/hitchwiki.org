# SharedInfobox

One infobox per concept, owned by the English wiki.

A translated article no longer carries its own `{{Infobox …}}`. Instead it
renders the infobox of its English counterpart, so a population figure, a
licence plate or a motorway list is corrected in exactly one place and every
language sees the correction.

This is the same principle as the shared news/events boxes (see
`$wgEnableScaryTranscluding` in `LocalSettings.php`), applied to infoboxes.

## How the counterpart is found

Through `page_translations`, the shared table maintained by
[CentralLangLinks](../CentralLangLinks/README.md), whose concept key *is* the
English page title. No new data store, and no per-page configuration.

## Why HTML crosses the wiki boundary, not wikitext

`Template:Infobox Location` and the templates it calls only exist on the English
wiki, so no other wiki could expand the wikitext even if it had it. The English
wiki is therefore asked, over the container-internal Apache (the same route the
`hwen` interwiki prefix takes), for the rendered lead section; the leading
`<table class="infobox …">` is cut out of it and injected at the top of the
local article body.

The injection happens in `OutputPageBeforeHTML`, after the parser cache: an edit
to the English infobox must not depend on eighteen wikis' parser caches being
invalidated. Instead the borrowed HTML has its own short TTL.

Links inside the borrowed infobox are pointed back at the local wiki wherever a
translation is known — including road articles such as `A9 (Germany)`, whose
German counterpart is reconstructed by translating only the country in the
disambiguator. Links with no local counterpart stay on the English article, and
links that were red on the English wiki become plain text, since inviting a
German reader to create an English article would be nonsense.

A page that still has its own infobox keeps it and is left alone, so a wiki can
be migrated article by article without a reader ever seeing two infoboxes.

## Migrating a language

Both scripts default to a dry run and write a TSV report.

1. **Move what only the translation knows into English.** Run on the English
   wiki; it reads the translated wiki over its API and edits English articles.

   ```
   php maintenance/run.php extensions/SharedInfobox/maintenance/mergeInfoboxes.php \
       --wiki=en --lang=de --report=/tmp/de-infoboxes.tsv [--apply]
   ```

   Values are merged only where the English infobox has nothing (or a `-`
   placeholder). Where both sides have a value the English one stands and the
   disagreement is reported. Parameters whose value is written in the source
   language, or in a notation English does not use, are reported for a human
   rather than merged — see the policies in `InfoboxMapping`.

2. **Remove the translated infoboxes.** Run on the translated wiki. An infobox
   is only removed after the English article has been confirmed, through the
   same request a reader's page view makes, to actually have one to put in its
   place.

   ```
   php maintenance/run.php extensions/SharedInfobox/maintenance/dropTranslatedInfoboxes.php \
       --wiki=de --report=/tmp/de-dropped.tsv [--apply]
   ```

   Whatever the local template did besides drawing a box — categorising the
   article, feeding the GeoCrumbs breadcrumb — is written back out as plain
   wikitext, so nothing changes but the box.

3. **Warm the cache**, so no reader pays for the first fetch of each page:
   request each changed page once.

Both scripts edit as `Maintenance script` with the bot flag set.

Adding another language means adding its infobox templates and their parameter
mapping to `InfoboxMapping::forLanguage()`; without an entry there both scripts
refuse to run. `englishParams()` covers the common case where a wiki copied the
English templates and its articles mix English parameter names with translated
ones — an English *name* says nothing about the value, so a country or region
still goes to review while a population figure does not.

Two rules keep the merge from making the English infobox worse: a value is
never merged if it calls a template this wiki does not have, and values written
in the source language (country names, currencies, ratings, free text) are
reported rather than merged.

## Translating articles that English does not have yet

An article can only inherit an infobox if the English counterpart exists. Where
English is missing articles — German rural districts and motorway service
stations were, as were a few Polish, Russian and Ukrainian places — they can be
translated across:

```
# 1. What is each German page called in English?
php maintenance/run.php extensions/SharedInfobox/maintenance/exportTitleMap.php \
    --wiki=en --lang=de --out=/tmp/de-titles.json --mapping=/tmp/de-mapping.json
#    (extend that title map with the titles you are about to create, so the
#     links between the new articles resolve. The parameter mapping is exported
#     from InfoboxMapping rather than restated in Python.)

# 2. Translate. Runs inside the container, which can reach both the wikis and
#    the OpenAI API. OPENAI_API_KEY comes from .env.
SHARED_INFOBOX_TITLEMAP=/tmp/de-titles.json SHARED_INFOBOX_MAPPING=/tmp/de-mapping.json \
  tools/translate_articles.py --lang de --template "Infobox Raste" --out /tmp/raste.jsonl

# 3. Create the English pages and pair them with their source.
php maintenance/run.php \
    extensions/SharedInfobox/maintenance/importTranslatedArticles.php \
    --wiki=en --lang=de --in=/tmp/raste.jsonl [--apply]
```

`tools/translate_articles.py` only ever shows the model prose. Template calls,
`<map>` tags, link *targets* and URLs are replaced by placeholders first and
restored afterwards, and the translation is rejected outright if a placeholder
comes back missing — so a translation cannot invent a template parameter or
quietly move a link. What is deliberately *not* masked is the visible text: a
link's label and an external link's caption are prose, and hiding them leaves
an English article captioned in the language the reader came here to avoid.
Infobox parameters, link targets, ratings, region names and thousands
separators are then converted in code.

When a translation still comes back malformed the article is cut finer —
paragraph, line, sentence — and only a fragment that fails every time is left
untranslated, which is visible in the result and far better than losing the
article.

Link targets with no English article stay red rather than being dropped: on a
wiki that is how the article gets asked for. But a label in another script is
replaced by the English title, and local common nouns that English would never
have an article for are unlinked.

## Configuration

| Setting | Default | |
|---|---|---|
| `$wgSharedInfoboxSourceLanguage` | `en` | The wiki that owns the infoboxes; never modified. |
| `$wgSharedInfoboxSourceApi` | `http://localhost/en/api.php` | Internal, so the fetch bypasses the public interstitial. |
| `$wgSharedInfoboxSourceArticlePath` | `/en/$1` | Used to recognise links that can be localised. |
| `$wgSharedInfoboxCacheExpiry` | `1800` | Seconds before the source wiki is asked again. |
| `$wgSharedInfoboxCacheType` | `CACHE_ANYTHING` | Falls back to the database, which matters because `$wgMainCacheType` is `CACHE_NONE` here and the WAN cache would store nothing at all. |

A copy is kept for 30 days and served stale if the source wiki is slow or
unreachable, so a bad minute on the English wiki never leaves a page bare.
