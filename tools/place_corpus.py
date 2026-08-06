#!/usr/bin/env python3
"""Shared index of the English wiki's place articles, and of what each wiki has.

`translate_place_articles.py` needs three facts that are expensive to work out
and cheap to keep: which English articles are places, which country each one is
in, and which of them every other wiki already has. All three are derived here
from the wikis themselves — no hand-maintained list of cities — and cached in
`tools/place_index.json`.

    tools/place_corpus.py build          # (re)build the index
    tools/place_corpus.py show de        # what one wiki has and is missing
"""

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse

CONTAINER = "hitchwiki-mediawiki"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
INDEX = os.path.join(ROOT, "tools", "place_index.json")
TEXT_CACHE = os.path.join(ROOT, "tools", "place_text_cache")

LANGUAGE_NAMES = {
    "ar": "Arabic", "bg": "Bulgarian", "cs": "Czech", "da": "Danish",
    "de": "German", "el": "Greek", "es": "Spanish", "et": "Estonian",
    "fa": "Persian", "fi": "Finnish", "fr": "French", "he": "Hebrew",
    "hr": "Croatian", "hu": "Hungarian", "it": "Italian", "ja": "Japanese",
    "ka": "Georgian", "lt": "Lithuanian", "lv": "Latvian", "mn": "Mongolian",
    "nl": "Dutch", "no": "Norwegian", "pl": "Polish", "pt": "Portuguese",
    "ro": "Romanian", "ru": "Russian", "sk": "Slovak", "sl": "Slovenian",
    "sr": "Serbian", "sv": "Swedish", "tr": "Turkish", "uk": "Ukrainian",
    "zh": "Chinese (Simplified)",
}
LANGS = sorted(LANGUAGE_NAMES)

# Wikis whose readers are hitchhiking in Europe. Countries go to these first:
# the user asked for European coverage before the rest of the world.
EUROPEAN = sorted({
    "bg", "cs", "da", "de", "el", "es", "et", "fi", "fr", "hr", "hu", "it",
    "lt", "lv", "nl", "no", "pl", "pt", "ro", "ru", "sk", "sl", "sr", "sv",
    "tr", "uk",
})

# Where each language's own readers are. Used for the "cities at home" tier:
# a Finn searching in Finnish wants Finnish cities before Rio de Janeiro.
# Only countries the language is actually spoken in, not everywhere it is
# taught — the point is the reader's own roads.
HOME_COUNTRIES = {
    "ar": ["Egypt", "Morocco", "Tunisia", "Algeria", "Jordan", "Lebanon",
           "Saudi Arabia", "United Arab Emirates", "Sudan", "Syria", "Iraq",
           "Libya", "Oman", "Yemen", "Kuwait", "Qatar", "Bahrain",
           "Western Sahara", "Mauritania", "Djibouti", "Somalia"],
    "bg": ["Bulgaria"],
    "cs": ["Czech Republic"],
    "da": ["Denmark", "Greenland", "Faroe Islands"],
    "de": ["Germany", "Austria", "Switzerland", "Liechtenstein", "Luxembourg"],
    "el": ["Greece", "Cyprus"],
    "es": ["Spain", "Mexico", "Argentina", "Colombia", "Chile", "Peru",
           "Venezuela", "Ecuador", "Bolivia", "Uruguay", "Paraguay", "Cuba",
           "Guatemala", "Honduras", "El Salvador", "Nicaragua", "Costa Rica",
           "Panama", "Dominican Republic", "Puerto Rico", "Equatorial Guinea"],
    "et": ["Estonia"],
    "fa": ["Iran", "Afghanistan", "Tajikistan"],
    "fi": ["Finland"],
    "fr": ["France", "Belgium", "Switzerland", "Luxembourg", "Monaco",
           "Canada", "Morocco", "Algeria", "Tunisia", "Senegal",
           "Côte d'Ivoire", "Mali", "Burkina Faso", "Niger", "Benin", "Togo",
           "Guinea", "Cameroon", "Gabon", "Madagascar", "Martinique",
           "Guadeloupe", "French Guiana", "New Caledonia", "Haiti",
           "Democratic Republic of the Congo", "Republic of the Congo",
           "Chad", "Central African Republic", "Djibouti", "Rwanda",
           "Burundi", "Saint Barthélemy"],
    "he": ["Israel"],
    "hr": ["Croatia", "Bosnia and Herzegovina"],
    "hu": ["Hungary"],
    "it": ["Italy", "San Marino", "Vatican", "Switzerland"],
    "ja": ["Japan"],
    "ka": ["Georgia", "Abkhazia"],
    "lt": ["Lithuania"],
    "lv": ["Latvia"],
    "mn": ["Mongolia"],
    "nl": ["Netherlands", "Belgium", "Suriname", "Aruba", "Curaçao"],
    "no": ["Norway"],
    "pl": ["Poland"],
    "pt": ["Portugal", "Brazil", "Angola", "Mozambique", "Cape Verde",
           "Guinea-Bissau", "São Tomé and Príncipe", "Timor-Leste", "Macau"],
    "ro": ["Romania", "Moldova", "Transnistria"],
    "ru": ["Russia", "Belarus", "Kazakhstan", "Kyrgyzstan", "Ukraine",
           "Moldova", "Uzbekistan", "Tajikistan", "Turkmenistan", "Armenia",
           "Azerbaijan", "Georgia", "Latvia", "Estonia", "Lithuania",
           "Abkhazia", "Transnistria", "Bashkortostan", "Tatarstan"],
    "sk": ["Slovakia"],
    "sl": ["Slovenia"],
    "sr": ["Serbia", "Bosnia and Herzegovina", "Montenegro", "Kosovo",
           "North Macedonia"],
    "sv": ["Sweden", "Finland", "Åland Islands"],
    "tr": ["Turkey", "Cyprus", "Azerbaijan"],
    "uk": ["Ukraine"],
    "zh": ["China", "Taiwan", "Hong Kong", "Macau", "Singapore"],
}

# Countries whose articles go out in the first tier. Everything in
# Category:Countries that sits on the European landmass or is a European
# reader's likely first destination; the rest of the world follows later.
EUROPEAN_COUNTRIES = [
    "Albania", "Andorra", "Armenia", "Austria", "Azerbaijan", "Belarus",
    "Belgium", "Bosnia and Herzegovina", "Bulgaria", "Croatia", "Cyprus",
    "Czech Republic", "Denmark", "England", "Estonia", "Faroe Islands",
    "Finland", "France", "Georgia", "Germany", "Gibraltar", "Greece",
    "Greenland", "Hungary", "Iceland", "Ireland", "Isle of Man", "Italy",
    "Jersey", "Kosovo", "Latvia", "Liechtenstein", "Lithuania", "Luxembourg",
    "Malta", "Moldova", "Monaco", "Montenegro", "Netherlands",
    "North Macedonia", "Northern Ireland", "Norway", "Poland", "Portugal",
    "Romania", "Russia", "San Marino", "Scotland", "Serbia", "Slovakia",
    "Slovenia", "Spain", "Sweden", "Switzerland", "Turkey", "Ukraine",
    "United Kingdom", "Vatican", "Wales", "Abkhazia", "Transnistria",
    "Kazakhstan", "Åland Islands",
]


# The articles that explain hitchhiking itself rather than a place. Taken from
# the wiki's own `Category:General info` — its answer to the same question —
# minus the pages below, plus a few techniques that live outside the category.
#
# Inbound link counts are useless for finding these: the motorway navboxes put
# `A7 (Germany)` on 206 pages and `Etiquette` on far fewer, so ranking by links
# returns nothing but roads.
EXTRA_CONCEPTS = [
    "Petrol station hitchhiking",
    "Official Hitchhiking",
    "Hitchhiking Bench",
    "Camping",
    "Hitchgathering",
    "Top tips",
    "Hitchhiker's safety",
    "Where to hitchhike",
    "Picking up hitchhikers",
    "Roles",
]

# Directories of English-language links, community rosters and news archives.
# Not informational articles, so not translated — the same reasoning that keeps
# the Community Portal in English.
CONCEPT_EXCLUDE = {
    "AVP Free Encyclopedia",
    "Hitch Team USA",
    "Hitchhiking clubs",
    "Hitchhiking news and news archive",
    "Liftari @ IRCnet",
    "Meetings",
    "North America Hitch Gathering",
    "Online Resources",
    "Traces",
}


# --------------------------------------------------------------------------
# wiki access
# --------------------------------------------------------------------------

def api(lang, params, retries=5):
    """Query a wiki's API from inside the container. Retried: php-fpm refuses
    connections under load."""
    params = dict(params, format="json", formatversion=2)
    url = f"http://localhost/{lang}/api.php?" + urllib.parse.urlencode(params)
    last = None
    for attempt in range(retries):
        r = subprocess.run(["docker", "exec", CONTAINER, "curl", "-s", "--max-time", "120", url],
                           capture_output=True, text=True)
        if r.returncode == 0 and r.stdout:
            try:
                return json.loads(r.stdout)
            except json.JSONDecodeError as e:
                last = f"{e}: {r.stdout[:200]}"
        else:
            last = f"curl exit {r.returncode}: {r.stderr.strip()}"
        time.sleep(2 * (attempt + 1))
    raise RuntimeError(f"{lang} API failed after {retries} tries: {last}")


def api_all(lang, params, key):
    """Follow query-continuation, yielding each batch's `query[key]`."""
    params = dict(params)
    while True:
        r = api(lang, params)
        if "query" in r and key in r["query"]:
            yield r["query"][key]
        if "continue" not in r:
            return
        params.update(r["continue"])


def sql(lang, query):
    """Rows from a read-only query, as dicts. sql.php prints PHP objects."""
    r = subprocess.run(
        ["docker", "exec", CONTAINER, "php", "/var/www/html/maintenance/run.php",
         "sql", f"--wiki={lang}", f"--query={query}"],
        capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"{lang}: {r.stderr.strip()[:400]}")
    rows, cur = [], None
    for line in r.stdout.splitlines():
        if line.startswith("stdClass Object"):
            if cur is not None:
                rows.append(cur)
            cur = {}
        elif cur is not None:
            m = re.match(r"\s+\[(\w+)\] => (.*)$", line)
            if m:
                cur[m.group(1)] = m.group(2)
    if cur:
        rows.append(cur)
    return rows


def get_wikitext(lang, title):
    return subprocess.run(
        ["docker", "exec", CONTAINER, "php", "/var/www/html/maintenance/run.php",
         "getText", f"--wiki={lang}", title],
        capture_output=True, text=True, check=True).stdout


def cached_wikitext(title):
    """English source text, cached on disk: it is read by every language."""
    path = os.path.join(TEXT_CACHE, slug(title) + ".txt")
    if not os.path.exists(path):
        fetch_wikitext(["en"] and [title])
    with open(path, encoding="utf-8") as f:
        return f.read()


def fetch_wikitext(titles):
    """Fill the on-disk cache for every title that is missing from it.

    One `getText` call boots MediaWiki from scratch, so fetching a few thousand
    articles that way takes the better part of an hour. The API returns fifty
    revisions per request instead.
    """
    os.makedirs(TEXT_CACHE, exist_ok=True)
    todo = [t for t in titles
            if not os.path.exists(os.path.join(TEXT_CACHE, slug(t) + ".txt"))]
    for i in range(0, len(todo), 50):
        chunk = todo[i:i + 50]
        r = api("en", {"action": "query", "titles": "|".join(chunk),
                       "prop": "revisions", "rvprop": "content",
                       "rvslots": "main"})
        got = {}
        q = r["query"]
        # Titles come back normalised; map them back to what we asked for.
        norm = {n["from"]: n["to"] for n in q.get("normalized", [])}
        for p in q.get("pages", []):
            if p.get("missing"):
                continue
            revs = p.get("revisions") or []
            if revs:
                got[p["title"]] = revs[0]["slots"]["main"].get("content", "")
        for t in chunk:
            text = got.get(norm.get(t, t))
            if text is None:
                continue
            with open(os.path.join(TEXT_CACHE, slug(t) + ".txt"), "w",
                      encoding="utf-8") as f:
                f.write(text)
        if i % 500 == 0:
            print(f"  wikitext {i}/{len(todo)}", file=sys.stderr)


def slug(title):
    """A filename-safe key for a page title, unique across titles.

    Squashing everything non-alphanumeric is lossy, and the corpus really does
    contain pairs that collapse together: `Constanţa` (t-cedilla) and
    `Constanța` (t-comma) are two separate articles. Any title the squash does
    not reproduce exactly therefore carries a hash of the real title, so two
    articles can never share a cache file, an output path or a batch id.
    """
    base = re.sub(r"[^A-Za-z0-9]+", "_", title).strip("_")[:100]
    if base == title:
        return base
    return (base or "x") + "-" + hashlib.sha1(title.encode("utf-8")).hexdigest()[:8]


# --------------------------------------------------------------------------
# building the index
# --------------------------------------------------------------------------

COUNTRY_PARAM_RE = re.compile(r"\|\s*country\s*=\s*\[?\[?([^\]\n|]+)", re.I)
ISIN_RE = re.compile(r"\{\{\s*IsIn\s*\|\s*([^}|]+)", re.I)


def category_members(lang, category):
    titles = []
    for batch in api_all(lang, {"action": "query", "list": "categorymembers",
                                "cmtitle": f"Category:{category}", "cmlimit": 500,
                                "cmnamespace": 0}, "categorymembers"):
        titles += [m["title"] for m in batch]
    return titles


def page_lengths(lang, titles):
    """{title: length} for the pages that exist, redirects resolved."""
    out = {}
    for i in range(0, len(titles), 50):
        chunk = titles[i:i + 50]
        r = api(lang, {"action": "query", "titles": "|".join(chunk),
                       "prop": "info", "redirects": 1})
        for p in r["query"].get("pages", []):
            if "missing" not in p and not p.get("missing"):
                out[p["title"]] = p.get("length", 0)
    return out


def inbound_counts(titles):
    """How many English articles link to each title — our proxy for how central
    a place is to the hitchhiking network, and so for how likely it is to be
    looked up."""
    rows = sql("en", (
        "SELECT lt_title t, COUNT(*) n FROM pagelinks "
        "JOIN linktarget ON lt_id=pl_target_id "
        "WHERE lt_namespace=0 GROUP BY lt_title"))
    counts = {r["t"].replace("_", " "): int(r["n"]) for r in rows if "t" in r}
    return {t: counts.get(t, 0) for t in titles}


def build_index():
    print("reading Category:Countries and Category:Cities on en …", file=sys.stderr)
    countries = sorted(set(category_members("en", "Countries")))
    cities = sorted(set(category_members("en", "Cities")))
    place = set(countries) | set(cities)
    concepts = sorted(
        (set(category_members("en", "General info")) | set(EXTRA_CONCEPTS))
        - CONCEPT_EXCLUDE - place)
    print(f"  {len(countries)} countries, {len(cities)} cities, "
          f"{len(concepts)} concepts", file=sys.stderr)

    every = countries + cities + concepts
    en_len = page_lengths("en", every)
    inbound = inbound_counts(every)

    print("caching English wikitext …", file=sys.stderr)
    fetch_wikitext(every)

    print("reading each city's country from its English infobox …", file=sys.stderr)
    city_country, unknown = {}, []
    for title in cities:
        try:
            text = cached_wikitext(title)
        except FileNotFoundError:
            unknown.append(title)
            continue
        m = COUNTRY_PARAM_RE.search(text) or ISIN_RE.search(text)
        if m:
            city_country[title] = m.group(1).strip().rstrip("|").strip()
        else:
            unknown.append(title)
    print(f"  {len(unknown)} cities with no country in the infobox", file=sys.stderr)

    print("reading what each wiki already has …", file=sys.stderr)
    # A concept counts as present on a wiki if page_translations names a live
    # page there, or if the wiki has a page under the English title itself.
    rows = sql("en", "SELECT pt_concept c, pt_lang l, pt_title t FROM page_translations")
    translations = {}
    for r in rows:
        if "c" in r:
            translations.setdefault(r["c"], {})[r["l"]] = r["t"]

    have = {}
    for lang in LANGS:
        titles = sorted({t for t in every} |
                        {translations.get(t, {}).get(lang) for t in every
                         if translations.get(t, {}).get(lang)})
        present = page_lengths(lang, [t for t in titles if t])
        got = {}
        for t in every:
            local = translations.get(t, {}).get(lang)
            if local and local in present:
                got[t] = [local, present[local]]
            elif t in present:
                got[t] = [t, present[t]]
        have[lang] = got
        print(f"  {lang}: {len(got)} of {len(every)}", file=sys.stderr)

    index = {
        "built": time.strftime("%Y-%m-%d %H:%M:%S"),
        "countries": countries,
        "cities": cities,
        "concepts": concepts,
        "en_length": en_len,
        "inbound": inbound,
        "city_country": city_country,
        "city_country_unknown": unknown,
        "translations": translations,
        "have": have,
    }
    with open(INDEX, "w", encoding="utf-8") as f:
        json.dump(index, f, ensure_ascii=False, indent=1, sort_keys=True)
    print(f"wrote {INDEX}", file=sys.stderr)
    return index


def load_index():
    if not os.path.exists(INDEX):
        raise SystemExit(f"{INDEX} missing — run: tools/place_corpus.py build")
    with open(INDEX, encoding="utf-8") as f:
        return json.load(f)


def cmd_build(args):
    build_index()
    return 0


def cmd_show(args):
    idx = load_index()
    have = idx["have"].get(args.lang, {})
    countries = idx["countries"]
    print(f"{args.lang}: has {len(have)} place articles")
    missing_c = [c for c in countries if c not in have]
    print(f"  countries: {len(countries) - len(missing_c)}/{len(countries)}")
    home = HOME_COUNTRIES.get(args.lang, [])
    home_cities = [c for c, k in idx["city_country"].items() if k in home]
    print(f"  home cities ({', '.join(home) or '—'}): "
          f"{sum(1 for c in home_cities if c in have)}/{len(home_cities)}")
    return 0


def main():
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("build").set_defaults(func=cmd_build)
    s = sub.add_parser("show")
    s.add_argument("lang")
    s.set_defaults(func=cmd_show)
    args = p.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
