#!/usr/bin/env bash
set -euo pipefail

# Source .env file
if [ -f .env ]; then
    set -a
    source .env
    set +a
fi

# Do the required hops in-order:
HOPS=("1.44", "1.45") # Start from currently running version

# Configuration: Languages based on available SQL files in ./sql/
LANGUAGES=()
for sqlfile in ./sql/hitchwiki_*.sql.gz; do
    [ -e "$sqlfile" ] || continue
    lang="$(basename "$sqlfile" .sql.gz | sed 's/hitchwiki_//')"
    LANGUAGES+=("$lang")
done

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
        if curl -fsS http://localhost:${MW_PORT} >/dev/null 2>&1; then
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

    backup

    echo "[!] Confirming your extensions/skins are on REL1_${VERSION/./_}"
    ./tools/upgrade_extensions.sh "$VERSION" ./

    docker compose up -d mediawiki
    docker compose pull mediawiki

    # TODO: Wait for mediawiki container to be healthy

    for wiki in "${LANGUAGES[@]}"; do
        run_maintenance_php update --wiki "$wiki" --quick

        # Only run runJobs on the last hop
        last_hop="${HOPS[@]: -1}"
        if [ "$VERSION" = "$last_hop" ]; then
            run_maintenance_php runJobs --wiki "$wiki"
        fi
    done

    echo "[✔] Hop to $VERSION complete."

    # TODO: Update .env?
done

echo "All hops done. Visit http://localhost:${MW_PORT}/en/Special:Version to verify."
