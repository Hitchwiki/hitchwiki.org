#!/usr/bin/env bash
set -euo pipefail

# Source .env file
if [ -f .env ]; then
    set -a
    source .env
    set +a
fi

# Do the required hops in-order:
# TODO: Possibly fix migration to 1.32 first (some languages fail due to missing tables – see below for patches)
HOPS=("1.33" "1.34" "1.35" "1.39" "1.44") # starting from 1.32 currently running

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
        docker compose exec -T mediawiki php maintenance/run.php "$script_name" "$@" || {
            echo "⚠️  Failed to run maintenance script $script_name, continuing..."
        }
    else
        docker compose exec -T mediawiki php maintenance/"$script_name".php "$@" || {
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

for VERSION in "${HOPS[@]}"; do
    echo "[*] Preparing to hop to $VERSION..."

    echo "==============================="
    echo "== Upgrading to MediaWiki $VERSION =="
    echo "==============================="

    export MW_VERSION="$VERSION"

    docker compose up -d db

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

    docker compose up -d mediawiki
    docker compose pull mediawiki

    # TODO: Wait for mediawiki container to be healthy

    for wiki in "${LANGUAGES[@]}"; do
        # Only run on the first hop
        if [ "$VERSION" = "${HOPS[0]}" ]; then
            echo "[*] First hop detected, running preparations on $wiki..."

            # Executing missing SQL patch from 1.30 upgrade for other languages than English
            if [ "$wiki" != "en" ]; then
                echo "[*] Applying missing 1.30 patch to $wiki..."
                docker exec -i hitchwiki-db mysql -u"root" -p"root" \
                    --database="${MEDIAWIKI_DB_NAME}_${wiki}" < ./patches/patch-comment-table.sql
            fi

            # Truncate actor table to start over
            echo "[*] Migrating actors for $wiki..."
            docker exec -i hitchwiki-db mysql -u"root" -p"root" \
                -e "TRUNCATE TABLE actor;" --database="${MEDIAWIKI_DB_NAME}_${wiki}"
            # TODO: Fails on Finnish wiki due to missing `revision_actor_temp` table, why? – Doesn't seem critical.
            run_maintenance_php cleanupUsersWithNoId --wiki "$wiki" --prefix "unknown" --assign

            # Truncate revision_comment_temp to start over (table might not exist yet)
            echo "[*] Migrating comments for $wiki..."
            docker exec -i hitchwiki-db mysql -u"root" -p"root" \
                -e "TRUNCATE TABLE revision_comment_temp;" --database="${MEDIAWIKI_DB_NAME}_${wiki}"
            run_maintenance_php migrateComments --wiki "$wiki" --force
        fi

        run_maintenance_php update --wiki "$wiki" --quick

        # Only run runJobs on the last hop
        last_hop="${HOPS[@]: -1}"
        if [ "$VERSION" = "$last_hop" ]; then
            run_maintenance_php runJobs --wiki "$wiki"

            # Fix revision comments with rev_comment_id = 0 (should be NULL or valid)
            # This is seemingly an empty comment; not sure why this happens
            echo "[*] Fixing revision comment IDs for $wiki..."
            docker exec -i hitchwiki-db mysql -u"root" -p"root" \
                -e "UPDATE revision SET rev_comment_id = 1 WHERE rev_comment_id = 0;" \
                --database="${MEDIAWIKI_DB_NAME}_${wiki}"
        fi
    done

    echo "[✔] Hop to $VERSION complete."
done

echo "All hops done. Visit http://localhost:${MW_PORT}/en/Special:Version to verify."
