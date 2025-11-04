# MediaWiki Upgrade Guide

This guide provides instructions for upgrading your MediaWiki installation. Upgrades should be approached with care to ensure data integrity and system stability.

## General Recommendations

- **Take your time**: Allocate sufficient time for each upgrade step. Rushed upgrades can lead to data loss or system instability. It is preferable to stay on an older stable version than to botch an upgrade.
- **Verify each step**: Carefully follow all instructions and verify that each step completes successfully before proceeding.
- **Backups are critical**: Always create comprehensive backups before starting any upgrade. Preserve these backups indefinitely, as they may be needed for future reference or rollback purposes.

## Upgrade Process

1. **Review Documentation**: Consult the official MediaWiki upgrade manual at [https://www.mediawiki.org/wiki/Manual:Upgrading](https://www.mediawiki.org/wiki/Manual:Upgrading) for detailed instructions.

2. **Check Release Notes**: Read the release notes for all versions between your current installation and the target version. Pay special attention and adapt accordingly to:
   - Breaking changes
   - New requirements
   - Deprecated features
   - Database schema changes

3. **LTS Releases**: We recommend upgrading only when a new Long Term Support (LTS) release becomes available, unless there are critical security fixes that require immediate attention.

4. **Upgrade Notes**: Review the upgrade notes specific to your target version. For example, for MediaWiki 1.44, see [https://gerrit.wikimedia.org/g/mediawiki/core/%2B/REL1_44/UPGRADE](https://gerrit.wikimedia.org/g/mediawiki/core/%2B/REL1_44/UPGRADE).

5. **Backup Strategy**:
   - Create full backups of your wiki database (MediaWiki files, extensions, skins, and configurations should be managed via Git repository and Docker, so no need to backup these separately)
   - Store backups in multiple locations and retain them indefinitely

6. **Testing**: After upgrade, thoroughly test your wiki functionality, including:
   - User login and permissions
   - Page editing and viewing
   - Extensions and custom features
   - Performance and responsiveness

7. **Rollback Plan**: Have a clear rollback procedure ready in case issues arise during or after the upgrade.

8. **Update Git Repository**: After successful upgrade, check if any extensions or skins are now bundled (or no longer bundled) in the new MediaWiki version. Update the Git repository accordingly - remove bundled extensions/skins that are no longer needed, and add any that were previously bundled but now need to be maintained separately. Avoid keeping bundled extensions in the repository unless necessary.

## Additional Resources

- [MediaWiki Release Notes](https://www.mediawiki.org/wiki/Release_notes)
- [MediaWiki Security Advisories](https://www.mediawiki.org/wiki/Special:MyLanguage/Security)
- Community forums and mailing lists for support

Remember: A successful upgrade requires patience, attention to detail, and comprehensive preparation. When in doubt, seek assistance from the MediaWiki community or other maintainers / contributors.