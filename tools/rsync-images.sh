#!/bin/bash

# ╔══════════════════════════════════════════════════════════════════════════════
# ║ DEPRECATED: This script is no longer maintained and will be removed
# ║ Please use the image dump functionality in import_dumps.sh instead
# ║ for syncing image files.
# ╚══════════════════════════════════════════════════════════════════════════════

# rsync images from production

cd $(dirname $0)/../htdocs/wiki

mkdir -p images
cd images
rsync -zav hitchwiki@h.bfr.ee:/var/www/hitchwiki.org/htdocs/wiki/images/ .
