# MediaWiki Upgrade Progress

## Goal
Upgrade MediaWiki (~1.32) → ≥1.39 with full revision history, file links, and user attribution intact.

## Current Status
- `upgrade-mw.sh` handles all upgrade steps, applying necessary SQL patches from `./patches`.
- Actor and comment migration is functional with these patches.
- Repository structure documented in `README.md`.
- Skins/extensions in Docker no longer an issue.

## Remaining Issues
- Faulty dumps affect Russian language specifically; need investigation.
- Most languages migrate without errors (few recoverable row failures – should we investigate?).
- Finnish on different upgrade stage but upgrades fine.
- Non-English languages not fully updated to 1.30; fixable with auto-applied SQL patch.

## Open Questions
1. Duplicate Actors: Merge, map accurately, or map to unknown actor?
2. Avoid duplicate actors in future?
3. Switch to safer collation?
