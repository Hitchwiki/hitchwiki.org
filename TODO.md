# Hitchwiki Project TODO

## Upgrade Progress
- [ ] Complete MediaWiki upgrade from 1.33 to 1.44 across all language databases
- [ ] Verify database schema integrity after upgrade
- [ ] Test wiki functionality in all languages (en, de, tr, etc.)

## Configuration
- [ ] Discuss necessary decisions on configuration changes
- [ ] Verify that configuration is complete, up-to-date and functional

## Extensions & Skins
- [ ] Check and update extensions for compatbility
- [ ] Check and update skins for compatibility
- [ ] Test extension functionality after upgrade
- [ ] Remove unneeded or outdated extensions

## Patches & Fixes
- [ ] Review and remove custom patches in `patches/` after deployment
- [ ] Publish documentation on our upgrade process for others

## Scripts
- [ ] Verify that all necessary tooling scripts are functional with Docker
- [ ] Verify that cronjobs can run with Docker

## Testing & Validation
- [ ] Test user authentication and permissions
- [ ] Verify image uploads and media handling
- [ ] Check extension tables were updated and persisted
- [ ] Test interwiki links and shared tables

## Deployment
- [ ] Prepare new production deployment including scripts / cronjobs
- [ ] Put production environment into readonly/maintenance mode
- [ ] Backup current production data and feed it into new deployment
- [ ] Upgrade in new deployment
- [ ] Switch over to new deployment
- [ ] Monitor for post-deployment issues