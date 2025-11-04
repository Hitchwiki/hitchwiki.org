#!/bin/bash
source "$(dirname "$0")/.common"

# Confirm execution
print_message "⚠️  WARNING: This script will import MediaWiki dumps and overwrite databases." $YELLOW
read -rp $'\033[0;33mAre you sure you want to proceed? [y/N] \033[0m' confirm
if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
  print_message "❌ Aborted by user." $RED
  exit 1
fi

# Check for --skip-fetch flag
SKIP_FETCH=false
for arg in "$@"; do
  if [[ "$arg" == "--skip-fetch" ]]; then
    SKIP_FETCH=true
  fi
done

# Fetch latest dumps unless --skip-fetch was passed
if [[ "$SKIP_FETCH" == false ]]; then
  print_message "📥 Fetching latest dumps..."
  ./fetch_dumps.sh
else
  print_message "⏭️ Skipping dump fetch (using local files)..." "$YELLOW"
fi

DUMP_DIR="./hitchwiki-dumps"
SCHEMA_DUMP="$DUMP_DIR/hitchwiki_en_schema.sql.gz"

# Detect all available languages from XML dumps
shopt -s nullglob
LANG_CODES=()
for dumpfile in "$DUMP_DIR"/hitchwiki-current-*.xml.gz; do
  lang="${dumpfile##*/}"
  lang="${lang#hitchwiki-current-}"
  lang="${lang%.xml.gz}"
  LANG_CODES+=("$lang")
done

# Reorder LANG_CODES to put MEDIAWIKI_DEFAULT_LANG first
if [[ " ${LANG_CODES[*]} " == *" ${MEDIAWIKI_DEFAULT_LANG} "* ]]; then
  NEW_LANG_CODES=("$MEDIAWIKI_DEFAULT_LANG")
  for lang in "${LANG_CODES[@]}"; do
    [[ "$lang" != "$MEDIAWIKI_DEFAULT_LANG" ]] && NEW_LANG_CODES+=("$lang")
  done
  LANG_CODES=("${NEW_LANG_CODES[@]}")
fi

if [ ${#LANG_CODES[@]} -eq 0 ]; then
  print_message "⚠️  No XML dumps found in $DUMP_DIR!" $RED
  exit 1
fi

# Step 1: Create DBs if needed and import schema
if [ -f "$SCHEMA_DUMP" ]; then
  for lang in "${LANG_CODES[@]}"; do
    db_name="${MEDIAWIKI_DB_NAME}_${lang}"
    DB_EXISTS=$(mysql -u"$MEDIAWIKI_DB_USER" -p"$MEDIAWIKI_DB_PASSWORD" -h"$MEDIAWIKI_DB_HOST" -P"$MEDIAWIKI_DB_PORT" -N -B -e \
      "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name';")

    if [[ "$DB_EXISTS" == "$db_name" ]]; then
      print_message "ℹ️  Database already exists: $db_name" $YELLOW
    else
        mysql -u"$MEDIAWIKI_DB_USER" -p"$MEDIAWIKI_DB_PASSWORD" -h"$MEDIAWIKI_DB_HOST" -P"$MEDIAWIKI_DB_PORT" -e \
          "CREATE DATABASE \`$db_name\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || {
        print_message "❌ Could not create database $db_name" $RED
        continue
      }
      print_message "✅ Database created: $db_name"
    fi

    zcat "$SCHEMA_DUMP" | \
      sed 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' | \
      sed 's/latin1/utf8mb4/g' | \
      mysql "$db_name" || {
        print_message "❌ Failed to import schema into $db_name" $RED
        continue
      }
    print_message "✅ Schema imported for $db_name"
  done
else
  print_message "⚠️  Schema dump not found. Skipping schema import." $YELLOW
fi

# Step 2: Import XML content dumps
for lang in "${LANG_CODES[@]}"; do
  dumpfile="$DUMP_DIR/hitchwiki-current-${lang}.xml.gz"

  zcat "$dumpfile" | \
    sed '1d' | \
    php ../wiki/maintenance/importDump.php --wiki $lang || {
      print_message "❌ Failed to import content for $lang" $RED
      continue
    }
  print_message "✅ Content imported for $lang"
done

# Step 3: Rebuild recent changes
print_message "🔄 Rebuilding recent changes for each language..."
for lang in "${LANG_CODES[@]}"; do
  php ../wiki/maintenance/rebuildrecentchanges.php --wiki $lang
done

# Step 4: Unpack images if archive exists
IMAGE_ARCHIVE="$DUMP_DIR/hitchwiki-images.tar.gz"

if [ -f "$IMAGE_ARCHIVE" ]; then
  print_message "🖼️ Unpacking images to temporary directory..."
  TEMP_DIR=$(mktemp -d)
  tar -xzf "$IMAGE_ARCHIVE" -C "$TEMP_DIR"
  
  print_message "📸 Importing images using MediaWiki importImages script..."
  php ../wiki/maintenance/importImages.php --wiki $MEDIAWIKI_DEFAULT_LANG "$TEMP_DIR" --search-recursively
  
  print_message "🧹 Cleaning up temporary directory..."
  rm -rf "$TEMP_DIR"
  print_message "✅ Images imported."
else
  print_message "ℹ️  No image archive found. Skipping image import." $YELLOW
fi

print_message "🎉 All imports completed successfully!"
