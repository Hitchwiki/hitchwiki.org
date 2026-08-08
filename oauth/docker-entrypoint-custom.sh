#!/bin/bash
set -e

OAUTH_DIR="/var/www/html/extensions/OAuth"
if [[ ! -f "$OAUTH_DIR/composer.json" ]]; then
	echo "[hitchwiki] ERROR: $OAUTH_DIR/composer.json missing (empty OAuth extension)." >&2
	echo "  From the hitchwiki.org repo run: git submodule update --init --recursive" >&2
	echo "  Or restore extensions/OAuth from your MediaWiki checkout." >&2
	exit 1
fi

echo "[hitchwiki] Installing OAuth extension composer packages..."
cd "$OAUTH_DIR"
# OAuth extension ships without composer.lock; Composer 2 blocks deps with open advisories unless opted out.
composer install --no-dev --no-interaction --no-security-blocking

echo "[hitchwiki] Verifying OAuth composer packages..."
# Verify the direct runtime dependencies; their transitive clock implementation varies by lockfile.
REQUIRED_PACKAGES=("firebase/php-jwt" "lcobucci/jwt" "league/oauth2-server")
MISSING=()
for pkg in "${REQUIRED_PACKAGES[@]}"; do
  if ! composer show "$pkg" > /dev/null 2>&1; then
    MISSING+=("$pkg")
  fi
done

if [ ${#MISSING[@]} -gt 0 ]; then
  echo "[hitchwiki] ERROR: Missing OAuth composer packages: ${MISSING[*]}"
  exit 1
fi

echo "[hitchwiki] All OAuth composer packages verified."

# Fix OAuth key file permissions so www-data can read them
if [ -f /var/www/html/oauth2.key ]; then
  chown root:www-data /var/www/html/oauth2.key /var/www/html/oauth2.pub
  chmod 660 /var/www/html/oauth2.key /var/www/html/oauth2.pub
  echo "[hitchwiki] OAuth key file permissions set."
fi

# Hand off to the default MediaWiki entrypoint
exec docker-php-entrypoint apache2-foreground
