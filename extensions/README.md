MediaWiki does not bundle all of these extensions, so they are pulled in here as git
submodules and baked into the image (`COPY extensions/` in the `Dockerfile`):

- AbuseFilter — bundled up to 1.38, a submodule since
- CheckUser — bundled up to 1.44, a submodule since

Both are in active use on the wiki family (see `wfLoadExtension` in
`wiki/LocalSettings.php`); they are not upgrade leftovers.

Local edits made inside an extension are not tracked by this repo — only the commit
each submodule is pinned to. Record any such edit in `EXTENSION_CHANGES.md` so it can
be reapplied on a fresh clone.
