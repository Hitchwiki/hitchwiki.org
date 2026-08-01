#!/usr/bin/env python3
"""Check that the tiles one article's infobox map needs are actually servable.

Mirrors the projection in data/hitchwiki-common.js exactly, so what it reports is
what a reader's browser will request from /tiles/{z}/{x}/{y}.png.

Usage:
    python3 tools/check_map_tiles.py en Praha [--url https://hitchwiki.org]
"""

import argparse
import os
import re
import subprocess
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seed_map_tiles import CONTAINER, TILE_ROOT, attr, tiles_for  # noqa: E402


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("lang")
    ap.add_argument("title")
    ap.add_argument("--url", help="also check over HTTP against this origin")
    args = ap.parse_args()

    text = subprocess.run(
        ["docker", "exec", CONTAINER, "php", "/var/www/html/maintenance/run.php",
         "getText", f"--wiki={args.lang}", args.title],
        capture_output=True, text=True,
    ).stdout
    tag = re.search(r"<map[^>]*>", text)
    if not tag:
        # A translated article carries no infobox of its own: SharedInfobox renders
        # the English one, so that is where the coordinates live.
        print(f"no <map> tag in {args.lang}:{args.title} (infobox may come from en)")
        return 2
    tag = tag.group(0)
    lat, lon, zoom = attr(tag, "lat"), attr(tag, "lng"), attr(tag, "zoom")
    print(f"{args.lang}:{args.title}  {tag.strip()}")
    print(f"  lat={lat} lon={lon} zoom={int(zoom)}\n")

    missing = 0
    for z, x, y in sorted(tiles_for(lat, lon, int(zoom))):
        rel = f"{z}/{x}/{y}.png"
        on_disk = os.path.isfile(os.path.join(TILE_ROOT, rel))
        line = f"  /tiles/{rel:<18} disk={'yes' if on_disk else 'NO '}"
        if args.url:
            try:
                # The edge rejects tool-ish user agents (Python-urllib gets a 403),
                # so ask the way a reader's browser would or the check is meaningless.
                req = urllib.request.Request(
                    f"{args.url.rstrip('/')}/tiles/{rel}",
                    headers={"User-Agent": "Mozilla/5.0 (hitchwiki.org tile check)"},
                )
                with urllib.request.urlopen(req, timeout=15) as r:
                    line += f"  http={r.status} {r.headers.get('content-type')}"
            except Exception as exc:  # noqa: BLE001 - reporting tool
                line += f"  http={exc}"
        if not on_disk:
            missing += 1
        print(line)

    print(f"\n{missing} of 9 tiles missing" if missing else "\nall 9 tiles present")
    return 1 if missing else 0


if __name__ == "__main__":
    sys.exit(main())
