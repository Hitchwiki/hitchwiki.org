#!/bin/bash
source "$(dirname "$0")/.common"

print_message "🌐 Importing interwiki map into database..."

API_URL="https://hitchwiki.org/en/api.php?action=query&meta=siteinfo&siprop=interwikimap&format=json"

# Fetch and convert to SQL inserts
SQL=$(curl -s "$API_URL" | jq -r --arg server "$MEDIAWIKI_SERVER" '
  .query.interwikimap[]
  | .url = (
      if (.url | test("^https?://hitchwiki.org/")) then
        (.url | sub("^https?://hitchwiki.org/?"; $server + "/"))
      else
        .url
      end
    )
  | "INSERT IGNORE INTO interwiki (iw_prefix, iw_url, iw_local, iw_trans, iw_api, iw_wikiid) VALUES (" +
    ("\"\(.prefix | gsub("\""; "\\\""))\"") + ", " +
    ("\"\(.url | gsub("\""; "\\\""))\"") + ", " +
    (if has("local") then "1" else "0" end) + ", 0, " +
    ("\"\(.api // "" | gsub("\""; "\\\""))\"") + ", " +
    "\"hitchwiki\"" + ");"
')

if [[ -z "$SQL" ]]; then
  print_message "❌ Failed to retrieve interwiki map or map is empty." $RED
  exit 1
fi

TARGET_DB="${MEDIAWIKI_DB_NAME}_${MEDIAWIKI_DEFAULT_LANG}"

# Clear previous interwiki entries from hitchwiki
print_message "🧹 Deleting old interwiki rows..."
$MYSQL_CMD "$TARGET_DB" -e "TRUNCATE TABLE interwiki;"

# Execute the generated SQL
print_message "📥 Inserting new interwiki entries..."
echo "$SQL" | $MYSQL_CMD "$TARGET_DB" || {
  print_message "❌ Failed to import interwiki map into $TARGET_DB" $RED
  exit 1
}

print_message "✅ Interwiki map imported into $TARGET_DB"
