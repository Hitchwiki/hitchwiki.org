# Data files

## Family-wide site JavaScript — `hitchwiki-common.js`

The JavaScript every language wiki shares: the infobox maps, the `Special:Block`
defaults, the coordinate and "add your own experience" prompts, and the user-page
Hitchwiki Maps banner.

This used to be `MediaWiki:Common.js` on each wiki separately. The 34 copies had
drifted into six different versions, and 26 of them had lost the infobox map code
entirely — `cs:Praha`, `nl:Nederland`, `sv:Sverige` and two dozen more rendered the
raw text `<map lat='50.07' lng='14.43' zoom='9' />` in the infobox where a map
belonged. It is now registered in `wiki/LocalSettings.php` as the
`hitchwiki.common` ResourceLoader module and loaded on every wiki.

Each wiki's own `MediaWiki:Common.js` is still loaded by core **on top of** this
module, so genuinely wiki-specific JavaScript is still possible. Do not paste the
shared code back into one: it would run twice, and the parts that toggle something
(the `Special:Block` checkboxes) would toggle straight back.

## Infobox map tiles — `../tiles/`

The infobox maps are a 3×3 mosaic of 256 px OpenStreetMap raster tiles. Those tiles
used to be fetched from `tile.openstreetmap.org` on every page view of ~4,500
articles, which is against the [OSM tile usage policy]
(https://operations.osmfoundation.org/policies/tiles/). They are now served from our
own origin at `/tiles/{z}/{x}/{y}.png`.

The tile set is finite and derivable: exactly the 3×3 neighbourhood around each
article's `<map>` tag, at that tag's zoom — 25,587 tiles, ~270 MB.

```bash
python3 tools/seed_map_tiles.py --dry-run     # what is referenced, what is missing
python3 tools/seed_map_tiles.py --rate 2      # download the missing tiles
python3 tools/check_map_tiles.py en Praha     # are one article's 9 tiles servable?
```

The directory is bind-mounted read-only into the container (`docker-compose.yml`)
and is **not** in git — it is reproducible at any time from the command above. A
weekly cron tops it up so articles created during the week get their tiles; until
then a missing tile falls back to OSM once, so a new map is never a blank hole.

`*.png` already bypasses Anubis in the Caddy `@hw_assets` matcher, so serving these
needed no front-end change.

## Infobox map pins — `../spots/`

Each map draws one clickable pin per well-rated hitchhiking spot inside its window,
linking to `maps.hitchwiki.org/spot/<lat>_<lon>`. `tools/build_map_spots.py` writes
one small file per map window, keyed by the same numbers the tile mosaic already
computes:

    spots/{zoom}/{startTileX}/{startTileY}.js

so the page fetches exactly one file and filters nothing. Rebuilt daily by cron from
maps.hitchwiki.org's `dist/spots.json`.

A spot is pinned when it is **rated ≥ 4 and has ≥ 3 reviews** — a lone five-star
review is one person's lucky afternoon, not a proven spot. That second condition is
demanding: 85% of well-rated spots have only one or two reviews, so it cuts 23,695
candidates to 3,485 and leaves **~2 pins on the average map, with 57% of maps
showing none at all**. Both thresholds are flags (`--min-rating`, `--min-reviews`);
`--min-reviews 2` would keep 12,516 spots if that proves too sparse.

Colours come from the **rounded** rating, so the amber/green split falls at 4.5, not
5.0: `data-rating="5"` (raw 4.5–5.0) is amber, everything else green.

Two traps worth remembering:

- **The key is the tile block, not the window.** Two articles a few hundred metres
  apart share a block but have windows offset by up to a whole tile, so each file
  holds every spot in the 3×3 block and the client clips to what it is drawing.
  Storing one article's window here put another article's pins off its map.
- **The extension is `.js`, and the contents are JSON.** Cloudflare fronts the site
  and answers a plain `.json` request with a "Just a moment…" interstitial (HTTP
  403); `.js` and `.css` pass as static assets. `fetch().json()` parses the body
  whatever Content-Type it arrives with.

Serving the data from our own origin also sidesteps CORS entirely —
maps.hitchwiki.org sends no `Access-Control-Allow-Origin`, so fetching its
`spots.json` from a hitchwiki.org page would be blocked in the browser.

**Cloudflare caches these files for four hours** (`max-age=14400`), so a rebuild
takes up to that long to reach readers. Fine for data that changes once a day; if a
change has to be visible immediately, purge the `/spots/*` prefix in Cloudflare or
check the origin with a `?cb=` query string.

## Country hitchability ratings

Per-country hitchability ratings are **no longer stored in this directory.** The
**HitchabilityRating** extension (`extensions/HitchabilityRating/`) now reads the
aggregate CSV that [maps.hitchwiki.org](https://maps.hitchwiki.org) exports, directly
from the path in the `HITCHABILITY_RATINGS_CSV` env var
(default `/var/www/maps.hitchwiki.org/dist/country_ratings.csv`), which is bind-mounted
into the container at the same path.

See [Country hitchability ratings](../README.md#country-hitchability-ratings) in the
main README for the column format (`country_code`, `hitchability`, `ride_count`), the
0–5 rounding rules, and how the file is wired up.
