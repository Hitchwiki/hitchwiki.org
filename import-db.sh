#!/usr/bin/env bash
set -euo pipefail

# Source .env file
if [ -f .env ]; then
  set -a
  source .env
  set +a
fi


for sqlfile in ./sql/hitchwiki_*.sql.gz; do
  [ -e "$sqlfile" ] || continue
  dbname="$(basename "$sqlfile" .sql.gz | sed 's/hitchwiki_//')"
  dbname="hitchwiki_$dbname"

  echo "[*] Creating database $dbname..."
  
  # Drop database if exists, then create
  docker exec -i hitchwiki-db mysql -uroot -proot \
    -e "DROP DATABASE IF EXISTS $dbname; CREATE DATABASE $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

  # Import schema first
  echo "[*] Importing schema from tools/hitchwiki-dumps/hitchwiki_en_schema.sql.gz..."
  gzcat ./tools/hitchwiki-dumps/hitchwiki_en_schema.sql.gz | docker exec -i hitchwiki-db sh -c "mysql -uroot -proot $dbname" || {
    echo "❌ Failed to import schema into $dbname"
    continue
  }

  # Import dump
  echo "[*] Importing dump from $sqlfile..."
  gzcat $sqlfile | docker exec -i hitchwiki-db sh -c "mysql -uroot -proot $dbname" || {
      echo "❌ Failed to import dump into $dbname"
  }

  # Grant access to default user
  docker exec -i hitchwiki-db mysql -uroot -proot \
    -e "GRANT ALL PRIVILEGES ON $dbname.* TO '$MEDIAWIKI_DB_USER'@'%'; FLUSH PRIVILEGES;"

  # Alter login method
  docker exec -i hitchwiki-db mysql -u"root" -p"root" \
      -e "ALTER USER '$MEDIAWIKI_DB_USER'@'%' IDENTIFIED WITH mysql_native_password BY '$MEDIAWIKI_DB_PASSWORD';"
done
