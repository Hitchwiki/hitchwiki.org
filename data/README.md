# Data files

## `country_ratings.csv`

Per-country hitchability ratings, used by the **HitchabilityRating** extension
(`extensions/HitchabilityRating/`) to resolve the `<rating country='xx'/>` tag in
country infoboxes into a hitchability sign.

**This file is not authoritative and should be regenerated from
[maps.hitchwiki.org](https://maps.hitchwiki.org).** The map is where hitchhikers log
their rides and rate them; this CSV is an aggregate export of that data. Refresh it
periodically so the wiki's country ratings stay in sync with the map.

### Format

```
country_code,average_rating,ride_count
AM,5,137
FR,4,9072
SG,2,2
```

- `country_code` — ISO 3166-1 alpha-2, upper-case (e.g. `GB` for the United Kingdom).
- `average_rating` — integer 1–5, mapped to sign templates in
  [Category:Templates Hitchability](https://hitchwiki.org/en/Category:Templates_Hitchability):
  `5` → very good, `4` → good, `3` → average, `2` → bad, `1` → senseless.
- `ride_count` — number of recorded rides the average is based on. Countries with
  fewer than 10 rides render `{{Unvalued}}` instead of a rating
  (configurable via `$wgHitchabilityRatingMinRides`).

### How it's used

The CSV is baked into the Docker image (`COPY data/` in the `Dockerfile`) and also
bind-mounted read-only in `docker-compose.yml`, so replacing this file and restarting
the container is enough to update ratings — no image rebuild required:

```bash
# after replacing data/country_ratings.csv with a fresh export from maps.hitchwiki.org
docker restart hitchwiki-mediawiki
```

Wiki country codes that differ from the ISO codes used here (e.g. the wiki uses `uk`
for the United Kingdom, ISO `GB`) are remapped via `$wgHitchabilityRatingAliases`.
