#!/bin/bash

# This is running weekly on production

cd $(dirname $0)

mysqldump hitchwiki_maps | gzip > ../htdocs/dumps/hitchwiki_maps.sql.gz
mysqldump --no-data hitchwiki_en | gzip > ../htdocs/dumps/hitchwiki_en_schema.sql.gz

cd ../wiki/images

tar zvcf ../../dumps/hitchwiki-images.tar.gz *

