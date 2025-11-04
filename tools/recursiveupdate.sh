#!/bin/bash

# ╔══════════════════════════════════════════════════════════════════════════════
# ║ DEPRECATED: This script is no longer maintained and will be removed
# ║ Please use install.sh instead, which now handles this update process.
# ║ See install.sh for the updated implementation.
# ╚══════════════════════════════════════════════════════════════════════════════

mapfile -t languages < <(echo "SHOW DATABASES;" | mysql | grep -E '^hitchwiki_..$' | sed 's/^hitchwiki_//g')

for lang in "${languages[@]}"; do
	echo "Running for $lang..."
	php wiki/maintenance/update.php --wiki="$lang" --quick
done
