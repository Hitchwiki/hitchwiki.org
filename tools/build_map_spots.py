#!/usr/bin/env python3
"""Build the per-map spot files that put clickable pins on the infobox maps.

Each infobox map is a fixed 300x300 window onto the world at the zoom its `<map>`
tag names, so the set of pins it can ever show is decided before anyone loads the
page. This writes one small JSON file per distinct map window, keyed by exactly the
values data/hitchwiki-common.js already computes for the tile mosaic:

    spots/{zoom}/{startTileX}/{startTileY}.js

so the client needs no bounding-box query, no index and no filtering — it fetches
one file and drops the pins in. The files are served from our own origin, which
also sidesteps CORS: maps.hitchwiki.org sends no Access-Control-Allow-Origin, so a
cross-origin fetch of its spots.json would be blocked in the browser.

The contents are JSON, but the extension is .js on purpose: Cloudflare sits in
front of the site and answers a plain .json request with an interstitial challenge
("Just a moment…", HTTP 403), while .js and .css sail through as static assets.
fetch().json() parses the body regardless of what Content-Type it arrives with.

Only spots that are both well rated and actually used are included — the point is
"the best hitchhiking spots", a lone five-star review is one person's lucky
afternoon, and at country zoom every spot at once is an unreadable blob.

Source: maps.hitchwiki.org's dist/spots.json export (regenerated there daily).

Usage:
    python3 tools/build_map_spots.py [--min-rating 4] [--min-reviews 3] [--max-pins 150]
"""

import argparse
import json
import math
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seed_map_tiles import (  # noqa: E402
    TILE_SIZE, VIEW_H, VIEW_W, attr, wikitext_map_tags,
)

SPOTS_JSON = "/var/www/maps.hitchwiki.org/dist/spots.json"
SPOT_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), os.pardir, "spots")

# Apache would otherwise hand a miss to MediaWiki's rewrite (a 301 into index.php)
# instead of answering 404, so every map without pins would cost a wiki request.
HTACCESS = "# Static spot data: never hand a miss to MediaWiki's rewrite rules.\nRewriteEngine Off\n"


def project(lat, lon, zoom):
    """Web Mercator world pixel, identical to the maths in hitchwiki-common.js."""
    n = 2 ** zoom
    x = ((lon + 180) / 360) * n * TILE_SIZE
    y = (
        (1 - math.log(math.tan(lat * math.pi / 180) + 1 / math.cos(lat * math.pi / 180)) / math.pi)
        / 2
    ) * n * TILE_SIZE
    return x, y


def spot_id(lat, lon):
    """The /spot/<lat>_<lon> permalink id.

    maps.hitchwiki.org matches ^-?\\d+\\.\\d{1,7}_-?\\d+\\.\\d{1,7}$, so the decimal
    point is mandatory — a whole-number coordinate has to keep its ".0" rather than
    be shortened away, which is exactly what JSON.stringify in a browser would do.
    Formatting the id here means the client never has to think about it.
    """
    def fmt(v):
        s = f"{v:.7f}".rstrip("0")
        return s + "0" if s.endswith(".") else s
    return f"{fmt(lat)}_{fmt(lon)}"


def map_windows():
    """Every distinct (zoom, startTileX, startTileY) an infobox map renders.

    The key is the tile block, which is coarser than the 300x300 window: two
    articles a few hundred metres apart land on the same block with their windows
    offset by up to a whole tile. So a file has to hold every spot in the *block*
    and let the client clip to whichever window it is actually drawing — keeping
    one article's window here would push another article's pins off its map.
    """
    windows = set()
    for tag in wikitext_map_tags():
        lat, lon, zoom = attr(tag, "lat"), attr(tag, "lng"), attr(tag, "zoom")
        if lat is None or lon is None or zoom is None:
            continue
        if not (-85 < lat < 85) or not (-180 <= lon <= 180) or not (0 <= zoom <= 19):
            continue
        zoom = int(zoom)
        cx, cy = project(lat, lon, zoom)
        top_x, top_y = cx - VIEW_W / 2, cy - VIEW_H / 2
        windows.add((zoom, math.floor(top_x / TILE_SIZE), math.floor(top_y / TILE_SIZE)))
    return windows


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--min-rating", type=float, default=4.0, help="lowest rating to pin (default 4)")
    # A single glowing review is one person's lucky afternoon. Requiring the spot to
    # have been used a few times keeps the pins to places that have actually proven
    # themselves — at the cost of most of them: 85% of well-rated spots have only
    # one or two reviews.
    ap.add_argument("--min-reviews", type=int, default=3,
                    help="fewest reviews a spot needs before it is pinned (default 3)")
    # A file covers the whole 3x3 tile block, which is roughly six times the area of
    # any one article's window, so the cap has to be generous or clipping to the
    # window could leave a city map half empty.
    ap.add_argument("--max-pins", type=int, default=150, help="most pins per tile block (default 150)")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    with open(SPOTS_JSON) as f:
        spots = json.load(f)
    good = [
        s for s in spots
        if (s.get("rating") or 0) >= args.min_rating
        and (s.get("review_count") or 0) >= args.min_reviews
    ]
    print(f"{len(spots)} spots in the export, {len(good)} rated >= {args.min_rating} "
          f"with >= {args.min_reviews} reviews")

    print("\nCollecting <map> tags from the wiki databases…")
    windows = map_windows()
    print(f"\n{len(windows)} distinct map windows")

    # Bucket the spots by zoom into the tile grid once, so each window only has to
    # look at the handful of cells it covers instead of all 25k spots.
    by_zoom = {}
    for zoom in sorted({z for z, _, _ in windows}):
        grid = {}
        for s in good:
            x, y = project(s["lat"], s["lon"], zoom)
            grid.setdefault((int(x // TILE_SIZE), int(y // TILE_SIZE)), []).append((x, y, s))
        by_zoom[zoom] = grid

    # The mosaic the client builds is this many tiles across.
    span_x = math.ceil(VIEW_W / TILE_SIZE) + 1
    span_y = math.ceil(VIEW_H / TILE_SIZE) + 1

    written = pinned = empty = 0
    for zoom, sx, sy in sorted(windows):
        grid = by_zoom[zoom]
        found = []
        for tx in range(sx, sx + span_x):
            for ty in range(sy, sy + span_y):
                found.extend(s for _, _, s in grid.get((tx, ty), ()))

        found.sort(key=lambda s: (-(s.get("rating") or 0), -(s.get("review_count") or 0)))
        found = found[: args.max_pins]
        payload = [
            {
                "lat": s["lat"],
                "lon": s["lon"],
                "id": spot_id(s["lat"], s["lon"]),
                "r": round(s.get("rating") or 0, 1),
                "n": s.get("review_count") or 0,
            }
            for s in found
        ]
        pinned += len(payload)
        if not payload:
            empty += 1

        if args.dry_run:
            continue
        path = os.path.join(SPOT_ROOT, str(zoom), str(sx), f"{sy}.js")
        os.makedirs(os.path.dirname(path), exist_ok=True)
        tmp = path + ".tmp"
        with open(tmp, "w") as f:
            json.dump(payload, f, separators=(",", ":"))
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)
        written += 1

    if not args.dry_run:
        os.makedirs(SPOT_ROOT, exist_ok=True)
        with open(os.path.join(SPOT_ROOT, ".htaccess"), "w") as f:
            f.write(HTACCESS)
        os.chmod(os.path.join(SPOT_ROOT, ".htaccess"), 0o644)

    print(f"\n{written} files written ({empty} with no spots), {pinned} pins total")
    print(f"average {pinned / max(len(windows), 1):.1f} pins per tile block")
    return 0


if __name__ == "__main__":
    sys.exit(main())
