#!/bin/bash
set -e

echo "[hitchwiki] Installing OAuth extension composer packages..."
cd /var/www/html/extensions/OAuth
composer install --no-dev --no-interaction

echo "[hitchwiki] Verifying OAuth composer packages..."
REQUIRED_PACKAGES=("firebase/php-jwt" "lcobucci/jwt" "league/oauth2-server" "okvpn/clock-lts")
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
