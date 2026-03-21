# OAuth Setup

This directory contains all files related to the MediaWiki OAuth extension setup.

## Files

- `oauth2.key` / `oauth2.pub` - RSA keypair used by the OAuth2 server (league/oauth2-server). These are mounted into the container at `/var/www/html/`.
- `docker-entrypoint-custom.sh` - Custom Docker entrypoint that installs and verifies OAuth composer dependencies on container startup, and sets correct permissions on the key files.

## Setup

### 1. Generate OAuth2 keys

If you don't already have keys, generate them:

```bash
openssl genrsa -out oauth/oauth2.key 2048
openssl rsa -in oauth/oauth2.key -pubout -out oauth/oauth2.pub
```

Set appropriate permissions:

```bash
chmod 660 oauth/oauth2.key oauth/oauth2.pub
```

### 2. Add database tables

Run the MediaWiki update script inside the container to create the OAuth database tables:

```bash
docker exec hitchwiki-mediawiki php maintenance/update.php
```

### 3. Install composer dependencies

This happens automatically on container startup via `docker-entrypoint-custom.sh`, which runs `composer install --no-dev` inside `extensions/OAuth` and verifies the required packages are present:

- `firebase/php-jwt`
- `lcobucci/jwt`
- `league/oauth2-server`
- `okvpn/clock-lts`

## Troubleshooting

- If the container fails to start with OAuth errors, check that the key files exist and have correct permissions (readable by `www-data`).
- If composer packages are missing, you can manually run `composer install --no-dev` inside the container's `/var/www/html/extensions/OAuth` directory.
