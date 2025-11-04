#!/bin/bash

# Make XML dumps of all databases
# For public consumption

cd "$(dirname "$0")"

# Create dumps directory if it doesn't exist
mkdir -p ../dumps

# Get languages from MySQL and store in an array
mapfile -t languages < <(echo "SHOW DATABASES;" | mysql | grep -E '^hitchwiki_..$' | sed 's/^hitchwiki_//g')

# Iterate through languages
for lang in "${languages[@]}"; do
	echo "Dumping $lang..."
	php maintenance/dumpBackup.php --wiki $lang --current 2>/dev/null | gzip > "../dumps/hitchwiki-current-$lang.xml.gz"
	php maintenance/dumpBackup.php --wiki $lang --full 2>/dev/null | gzip > "../dumps/hitchwiki-full-$lang.xml.gz"
done