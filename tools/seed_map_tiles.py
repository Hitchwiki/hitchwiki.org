#!/usr/bin/env python3
"""Seed the local OpenStreetMap tile cache that the infobox maps are drawn from.

Every article infobox carries a `<map lat=… lng=… zoom=… />` tag, which
data/hitchwiki-common.js turns into a 300x300 mosaic of raster tiles. Those tiles
used to be fetched from tile.openstreetmap.org on every single page view, which is
against the OSM tile usage policy and puts the readability of ~4,500 articles at
the mercy of a third party.

The set of tiles those maps can ever ask for is finite and derivable: it is exactly
the 3x3 neighbourhood around each `<map>` tag's centre, at that tag's zoom. This
script enumerates the tags straight out of the wiki databases, works out that set,
and downloads whatever is missing into TILE_ROOT, which is served from our own
origin at /tiles/{z}/{x}/{y}.png.

It is incremental and interruptible: tiles already on disk are never refetched, so
a re-run after new articles appear costs only the genuinely new tiles. Run it from
cron to keep up with new articles.

Usage:
    python3 tools/seed_map_tiles.py [--dry-run] [--limit N] [--rate R]
"""

import argparse
import math
import os
import re
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

# The language wikis, mirroring $hwLanguages in wiki/LocalSettings.php.
LANGUAGES = (
    "en ar bg cs da de el es et fa fi fr he hr hu it ja ka lt lv mn nl no pl pt "
    "ro ru sk sl sr sv tr uk zh"
).split()

CONTAINER = "hitchwiki-mediawiki"
TILE_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), os.pardir, "tiles")
TILE_URL = "https://tile.openstreetmap.org/{z}/{x}/{y}.png"

# Without this, Apache hands a missing tile to MediaWiki's rewrite rules — a 301
# into index.php — instead of answering 404. Written on every run so a wiped cache
# directory (it is not in git) comes back complete.
HTACCESS = "# Static tiles: never hand a miss to MediaWiki's rewrite rules.\nRewriteEngine Off\n"

# OSM asks that every client identify itself and stay well clear of bulk rates.
# One request per second, single threaded, is the ceiling their policy suggests
# for a job like this; the whole seed is a few hours and only ever runs once.
USER_AGENT = "hitchwiki.org infobox map tiles (+https://hitchwiki.org; wiki@hitchwiki.org)"
DEFAULT_RATE = 1.0

TILE_SIZE = 256
# The viewport data/hitchwiki-common.js draws, and the +1 tile of slack it adds so
# a partially covered edge still has a tile behind it.
VIEW_W = VIEW_H = 300
MAX_TAGS_PER_PAGE = 8


def wikitext_map_tags():
    """Every `<map …>` tag in article space, across every language wiki."""
    # REGEXP_SUBSTR takes an occurrence index, so the handful of articles that
    # carry more than one map tag are covered without ever dragging whole article
    # texts through sql.php (which mangles long lines).
    occurrences = " UNION ALL ".join(
        f"SELECT REGEXP_SUBSTR(CONVERT(t.old_text USING utf8mb4), '<map[^>]*>', 1, {i}) AS m "
        "FROM page p "
        "JOIN slots s ON s.slot_revision_id = p.page_latest "
        "JOIN content c ON c.content_id = s.slot_content_id "
        "JOIN text t ON t.old_id = SUBSTRING(c.content_address, 4) "
        "WHERE p.page_namespace = 0 AND t.old_text LIKE '%<map lat%'"
        for i in range(1, MAX_TAGS_PER_PAGE + 1)
    )
    query = f"SELECT m FROM ({occurrences}) AS tags WHERE m IS NOT NULL AND m <> ''"

    for lang in LANGUAGES:
        proc = subprocess.run(
            [
                "docker", "exec", CONTAINER,
                "php", "/var/www/html/maintenance/run.php", "sql",
                f"--wiki={lang}", f"--query={query}",
            ],
            capture_output=True, text=True,
        )
        if proc.returncode != 0:
            print(f"  ! {lang}: sql.php failed, skipping", file=sys.stderr)
            continue
        found = 0
        for line in proc.stdout.splitlines():
            line = line.strip()
            if line.startswith("[m] => <map"):
                found += 1
                yield line[len("[m] => "):]
        print(f"  {lang}: {found} map tags")


def attr(tag, name):
    m = re.search(name + r"""\s*=\s*['"]?(-?\d+(?:\.\d+)?)""", tag)
    return float(m.group(1)) if m else None


def tiles_for(lat, lon, zoom):
    """The tiles the mosaic for one `<map>` tag will request."""
    n = 2 ** zoom
    cx = ((lon + 180) / 360) * n * TILE_SIZE
    cy = (
        (1 - math.log(math.tan(lat * math.pi / 180) + 1 / math.cos(lat * math.pi / 180)) / math.pi)
        / 2
    ) * n * TILE_SIZE
    start_x = math.floor((cx - VIEW_W / 2) / TILE_SIZE)
    start_y = math.floor((cy - VIEW_H / 2) / TILE_SIZE)
    for dx in range(math.ceil(VIEW_W / TILE_SIZE) + 1):
        for dy in range(math.ceil(VIEW_H / TILE_SIZE) + 1):
            x, y = start_x + dx, start_y + dy
            if 0 <= x < n and 0 <= y < n:
                yield zoom, x, y


def required_tiles():
    wanted, skipped = set(), 0
    for tag in wikitext_map_tags():
        lat, lon, zoom = attr(tag, "lat"), attr(tag, "lng"), attr(tag, "zoom")
        if lat is None or lon is None or zoom is None:
            skipped += 1
            continue
        # Outside these bounds the Mercator projection is undefined or the tag is
        # simply junk; either way there is no tile to fetch.
        if not (-85 < lat < 85) or not (-180 <= lon <= 180) or not (0 <= zoom <= 19):
            skipped += 1
            continue
        wanted.update(tiles_for(lat, lon, int(zoom)))
    if skipped:
        print(f"  ({skipped} tags skipped: no usable coordinates)")
    return wanted


def fetch(z, x, y, path):
    req = urllib.request.Request(TILE_URL.format(z=z, x=x, y=y), headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=30) as resp:
        data = resp.read()
    if not data.startswith(b"\x89PNG"):
        raise ValueError("not a PNG")
    os.makedirs(os.path.dirname(path), exist_ok=True)
    # Write via a temp file in the same directory: a half-written tile must never
    # become visible to Apache, and a re-run must not have to second-guess the cache.
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(path), suffix=".tmp")
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
        # mkstemp creates 0600; Apache in the container runs as www-data and has
        # to be able to read the tile it is about to serve.
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)
    except BaseException:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
    return len(data)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--dry-run", action="store_true", help="report what is missing, download nothing")
    ap.add_argument("--limit", type=int, help="stop after downloading this many tiles")
    ap.add_argument("--rate", type=float, default=DEFAULT_RATE, help="requests per second (default 1)")
    args = ap.parse_args()

    os.makedirs(TILE_ROOT, exist_ok=True)
    guard = os.path.join(TILE_ROOT, ".htaccess")
    with open(guard, "w") as f:
        f.write(HTACCESS)
    os.chmod(guard, 0o644)

    print("Collecting <map> tags from the wiki databases…")
    wanted = required_tiles()
    print(f"\n{len(wanted)} distinct tiles referenced by infobox maps")

    missing = sorted(t for t in wanted if not os.path.isfile(os.path.join(TILE_ROOT, str(t[0]), str(t[1]), f"{t[2]}.png")))
    have = len(wanted) - len(missing)
    print(f"{have} already cached, {len(missing)} to download")

    if args.dry_run or not missing:
        return 0

    interval = 1.0 / args.rate if args.rate > 0 else 0
    todo = missing[: args.limit] if args.limit else missing
    done = failed = written = 0
    started = time.time()

    for z, x, y in todo:
        path = os.path.join(TILE_ROOT, str(z), str(x), f"{y}.png")
        try:
            written += fetch(z, x, y, path)
            done += 1
        except (urllib.error.URLError, ValueError, OSError) as exc:
            failed += 1
            print(f"  ! {z}/{x}/{y}: {exc}", file=sys.stderr)
        if done % 250 == 0 and done:
            rate = done / max(time.time() - started, 1e-9)
            eta = (len(todo) - done) / rate / 60
            print(f"  {done}/{len(todo)} tiles, {written / 1048576:.0f} MB, ~{eta:.0f} min left")
        if interval:
            time.sleep(interval)

    print(f"\nDone: {done} tiles ({written / 1048576:.0f} MB), {failed} failed")
    return 1 if failed and not done else 0


if __name__ == "__main__":
    sys.exit(main())
