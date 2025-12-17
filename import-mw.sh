#!/usr/bin/env bash
set -euo pipefail

# Source .env file
if [ -f .env ]; then
    set -a
    source .env
    set +a
fi

# NOTE:
# THIS SCRIPT IS A WIP TO ALLOW USERS TO IMPORT SQL DUMPS.
# A BIT OF THIS IS DUPLICATE / REDUNDANT WITH import-db.sh.

# Configuration: Languages based on available SQL files in ./sql/
LANGUAGES=()
for sqlfile in ./sql/hitchwiki_*.sql.gz; do
    [ -e "$sqlfile" ] || continue
    lang="$(basename "$sqlfile" .sql.gz | sed 's/hitchwiki_//')"
    LANGUAGES+=("$lang")
done

import_sql() {
    # Alter login method
    docker exec -i hitchwiki-db mysql -u"root" -p"root" \
        -e "ALTER USER '$MEDIAWIKI_DB_USER'@'%' IDENTIFIED WITH mysql_native_password BY '$MEDIAWIKI_DB_PASSWORD';"

    for sqlfile in ./sql/hitchwiki_*.sql.gz; do
        [ -e "$sqlfile" ] || continue
        dbname="$(basename "$sqlfile" .sql.gz | sed 's/hitchwiki_//')"
        dbname="hitchwiki_$dbname"
        exists=$(docker exec -i hitchwiki-db mysql -u"root" -p"root" \
            -e "SHOW DATABASES LIKE '$dbname';" | grep "$dbname" || true)
        if [ -z "$exists" ]; then
            echo "[*] Creating and importing $dbname from $sqlfile..."
            docker exec -i hitchwiki-db mysql -u"root" -p"root" \
                -e "CREATE DATABASE $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

            # Grant access to default user
            docker exec -i hitchwiki-db mysql -u"root" -p"root" \
                -e "GRANT ALL PRIVILEGES ON $dbname.* TO '$MEDIAWIKI_DB_USER'@'%'; FLUSH PRIVILEGES;"

            # Import dump
            gunzip -c "$sqlfile" \
                | sed -e '/^INSERT INTO `objectcache`/d' -e '/^INSERT INTO `l10n_cache`/d' \
                | docker exec -i hitchwiki-db sh -c "mysql -uroot -proot $dbname" || {
                    echo "⚠️  Failed to import $sqlfile into $dbname, continuing..."
                }
        else
            echo "[*] Database $dbname already exists, skipping import."
        fi
    done
}

backup() {
    ts="$(date +%Y%m%d-%H%M%S)"
    mkdir -p backups
    echo "[*] Backing up DB and images..."
    docker exec -i hitchwiki-db mysqldump -u"$MEDIAWIKI_DB_USER" -p"$MEDIAWIKI_DB_PASSWORD" "$MEDIAWIKI_DB_NAME" \
        > "backups/db-$ts.sql"
    docker run --rm -v mediawiki_images:/data -v "$PWD/backups:/backup" alpine \
        sh -c "cd /data && tar -czf /backup/images-$ts.tgz ."
}

run_maintenance_php() {
    local script_name="$1"
    shift 1
    echo "[*] Running $script_name script..."
    if [[ "$(printf '%s\n' "1.40" "$MW_VERSION" | sort -V | head -n1)" == "1.40" ]]; then
        docker compose -f docker-compose.dev.yml exec -T mediawiki php maintenance/run.php "$script_name" "$@" || {
            echo "⚠️  Failed to run maintenance script $script_name, continuing..."
        }
    else
        docker compose -f docker-compose.dev.yml exec -T mediawiki php maintenance/"$script_name".php "$@" || {
            echo "⚠️  Failed to run maintenance script $script_name, continuing..."
        }
    fi
}

health_wait() {
    echo "[*] Waiting for MediaWiki to respond..."
    for i in {1..60}; do
        if curl -fsS http://localhost:8080 >/dev/null 2>&1; then
            return 0
        fi
        sleep 3
    done
    echo "Health check failed"; exit 1
}

# Start import of all files in ./sql/hitchwiki_*.sql.gz
echo "[*] Preparing to import to MediaWiki $VERSION..."

echo "==============================="
echo "== Importing to MediaWiki $VERSION =="
echo "==============================="


docker compose -f docker-compose.dev.yml up -d db

# Wait for the db container to be healthy
echo "[*] Waiting for db container to be healthy..."
for i in {1..30}; do
    status=$(docker inspect --format='{{.State.Health.Status}}' hitchwiki-db 2>/dev/null || echo "starting")
    if [ "$status" = "healthy" ]; then
        break
    fi
    sleep 2
done

if [ "$status" != "healthy" ]; then
    echo "Database container is not healthy after waiting, aborting."
    exit 1
fi

import_sql
backup

echo "[!] Confirming your extensions/skins are on REL1_${VERSION/./_}"
./tools/upgrade_extensions.sh "$VERSION" ./

docker compose -f docker-compose.dev.yml up -d

echo "[✔] Import on MediaWiki $VERSION complete."

echo "All hops done. Visit http://localhost:${MW_PORT}/en/Special:Version to verify."
