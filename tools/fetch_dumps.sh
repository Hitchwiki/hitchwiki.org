#!/bin/bash
source "$(dirname "$0")/.common"

# Directory for dumps
DUMP_DIR="hitchwiki-dumps"
mkdir -p "$DUMP_DIR"
cd "$DUMP_DIR"

# Base URL
BASE_URL="https://hitchwiki.org/dumps"

# Available language codes (sorted and compact)
# Fetch and extract language codes from the dump index
AVAILABLE_DUMPS=$(curl -s https://hitchwiki.org/dumps/ | grep -oP 'hitchwiki-(current|full)-\K[a-z]{2}(?=\.xml\.gz)' | sort -u)
LANGUAGES=($AVAILABLE_DUMPS)

# Ask user which languages to download
print_message "🌐 Available languages:"
echo "${LANGUAGES[@]}" | fold -w 50 -s
read -rp $'\033[0;34mEnter language codes (space-separated or "all") [default: all]: \033[0m' lang_input
lang_input="${lang_input:-all}"

if [[ "$lang_input" == "all" ]]; then
  SELECTED_LANGS=("${LANGUAGES[@]}")
else
  SELECTED_LANGS=($lang_input)
fi

# Ask user for dump types
echo -e "${BLUE}📦 Choose which types of dumps to fetch:${NC}"
echo "  1) Current dumps only"
echo "  2) Full dumps only"
echo "  3) Both current and full"
read -rp $'\033[0;34mEnter your choice [default: 1]: \033[0m' type_choice
type_choice="${type_choice:-1}"

# Ask for optional schema/maps/images
read -rp $'\033[0;34mInclude schema and maps SQL dumps? [Y/n, default: Y]: \033[0m' include_sql
include_sql="${include_sql:-y}"

read -rp $'\033[0;34mInclude hitchwiki-images.tar.gz? [Y/n, default: Y]: \033[0m' include_images
include_images="${include_images:-y}"

print_message "🔍 Preparing download list..."

# Build download list
DOWNLOADS=()

# Add current/full per language
for lang in "${SELECTED_LANGS[@]}"; do
  if [[ "$type_choice" == "1" || "$type_choice" == "3" ]]; then
    DOWNLOADS+=("hitchwiki-current-$lang.xml.gz")
  fi
  if [[ "$type_choice" == "2" || "$type_choice" == "3" ]]; then
    DOWNLOADS+=("hitchwiki-full-$lang.xml.gz")
  fi
done

# Add extras if selected
[[ "$include_sql" =~ ^[Yy]$ ]] && DOWNLOADS+=("hitchwiki_en_schema.sql.gz" "hitchwiki_maps.sql.gz")
[[ "$include_images" =~ ^[Yy]$ ]] && DOWNLOADS+=("hitchwiki-images.tar.gz")

# Download files
print_message "⬇️  Downloading selected dumps..."
for file in "${DOWNLOADS[@]}"; do
  if wget -N "$BASE_URL/$file"; then
    print_message "✔️  Downloaded: $file"
  else
    print_message "⚠️  Failed to download: $file" $YELLOW
  fi
done

print_message "✅ All downloads complete."
