#!/bin/bash

cd $(dirname $0)/..

# Dump all databases

# Not for public consumption

DBS=$(mysql -e 'SHOW DATABASES\G'|grep hitchwiki|awk '{ print $2 }')
echo $DBS

mkdir -p sqldumps
cd sqldumps

for db in $DBS; do
    echo "Dumping $db"
    mysqldump $db | gzip > $db.sql.gz
done
