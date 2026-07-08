# Data files

## Country hitchability ratings

Per-country hitchability ratings are **no longer stored in this directory.** The
**HitchabilityRating** extension (`extensions/HitchabilityRating/`) now reads the
aggregate CSV that [maps.hitchwiki.org](https://maps.hitchwiki.org) exports, directly
from the path in the `HITCHABILITY_RATINGS_CSV` env var
(default `/var/www/maps.hitchwiki.org/dist/country_ratings.csv`), which is bind-mounted
into the container at the same path.

See [Country hitchability ratings](../README.md#country-hitchability-ratings) in the
main README for the column format (`country_code`, `hitchability`, `ride_count`), the
0–5 rounding rules, and how the file is wired up.
