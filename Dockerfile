ARG MW_VERSION
FROM mediawiki:${MW_VERSION}

WORKDIR /var/www/html

# Copy .htaccess
COPY wiki/.htaccess .

# Copy LocalSettings.php if you want to bundle it (optional, usually mounted in production)
# TODO: Need to test if this works; I believe the MediaWiki image might not allow for this.
# COPY wiki/LocalSettings.php .

# Copy local extensions and skins
COPY skins/ /var/www/html/skins/
COPY extensions/ /var/www/html/extensions/

# Set permissions for local extensions
RUN chown -R www-data:www-data /var/www/html/extensions /var/www/html/skins

# Copy favicon
COPY wiki/favicon.ico .
COPY wiki/favicon.png .

# Install composer for extension dependency management
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Install git (required by composer for dev-branch dependencies like league/oauth2-server)
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*

# Custom entrypoint: installs & verifies OAuth composer packages on startup
COPY oauth/docker-entrypoint-custom.sh /usr/local/bin/
ENTRYPOINT ["docker-entrypoint-custom.sh"]
