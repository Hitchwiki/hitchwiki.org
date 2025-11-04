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

# If extensions need composer install, uncomment and adjust:
# RUN composer update --no-dev
