#!/usr/bin/env python3
"""Translate Hitchwiki articles from any language wiki into English wikitext.

Only prose is sent to the language model. Everything that carries structure or
identity — template calls, wikilinks, HTML tags, image names, coordinates — is
replaced by a placeholder first and put back afterwards, so a translation can
never silently invent a template parameter or point a link somewhere else.
Infobox parameters, link targets and hitchability ratings are then mapped to
their English equivalents deterministically, from page_translations and from
the tables below.

Reads the article list and the source wikitext from that wiki's API and writes
JSONL for importTranslatedArticles.php to load into the English wiki. The
infobox parameter mapping is not duplicated here: it is exported from
InfoboxMapping by exportTitleMap.php --mapping and read back.

    tools/translate_articles.py --lang de --template "Infobox Raste" --out raste.jsonl
"""

import argparse
import concurrent.futures
import json
import os
import re
import sys
import threading
import time
import urllib.parse
import urllib.request

OPENAI_URL = "https://api.openai.com/v1/chat/completions"
DEFAULT_MODEL = "gpt-5.6-terra"


def api_url(lang):
    return f"http://localhost/{lang}/api.php"


# Things the English wiki names differently from the wiki they come from, and
# which are not derivable from page_translations because they are values inside
# a parameter rather than page titles.
REGIONS = {
    "de": {
        "Niedersachsen": "Lower Saxony",
        "Bayern": "Bavaria",
        "Nordrhein-Westfalen": "North Rhine-Westphalia",
        "Hessen": "Hesse",
        "Rheinland-Pfalz": "Rhineland-Palatinate",
        "Sachsen": "Saxony",
        "Sachsen-Anhalt": "Saxony-Anhalt",
        "Thüringen": "Thuringia",
    },
    "pl": {
        "dolnośląskie": "Lower Silesian Voivodeship",
        "kujawsko-pomorskie": "Kuyavian-Pomeranian Voivodeship",
        "lubelskie": "Lublin Voivodeship",
        "lubuskie": "Lubusz Voivodeship",
        "łódzkie": "Łódź Voivodeship",
        "małopolskie": "Lesser Poland Voivodeship",
        "mazowieckie": "Masovian Voivodeship",
        "opolskie": "Opole Voivodeship",
        "podkarpackie": "Subcarpathian Voivodeship",
        "podlaskie": "Podlaskie Voivodeship",
        "pomorskie": "Pomeranian Voivodeship",
        "śląskie": "Silesian Voivodeship",
        "świętokrzyskie": "Holy Cross Voivodeship",
        "warmińsko-mazurskie": "Warmian-Masurian Voivodeship",
        "wielkopolskie": "Greater Poland Voivodeship",
        "zachodniopomorskie": "West Pomeranian Voivodeship",
    },
}

# Hitchability rating templates, per wiki. Where a wiki grades more finely than
# English does, the coarser English template it already points at is used.
RATINGS = {
    "de": {
        "sehr gut": "very good", "gut": "good", "durchschnittlich": "average",
        "weniger gut": "average", "schlecht": "bad", "sinnlos": "senseless",
        "unbewertet": "unvalued",
    },
    "pl": {
        "bardzo dobrze": "very good", "dobrze": "good", "średnio": "average",
        "źle": "bad", "beznadziejnie": "senseless", "nieoceniony": "unvalued",
    },
    "ru": {
        "отлично": "very good", "хорошо": "good", "средне": "average",
        "плохо": "bad", "бессмысленно": "senseless", "не оценено": "unvalued",
    },
    "uk": {
        "дуже добре": "very good", "добре": "good", "середньо": "average",
        "погано": "bad", "безглуздо": "senseless", "не оцінено": "unvalued",
    },
}

# The template namespace on each wiki, needed to list a template's articles.
TEMPLATE_NS = {
    "de": "Vorlage", "pl": "Szablon", "ru": "Шаблон", "uk": "Шаблон",
    "fr": "Modèle", "it": "Template", "pt": "Predefinição", "fi": "Malline",
    "zh": "Template", "es": "Plantilla", "nl": "Sjabloon",
}

LANGUAGE_NAMES = {
    "de": "German", "pl": "Polish", "ru": "Russian", "uk": "Ukrainian",
    "fr": "French", "it": "Italian", "pt": "Portuguese", "fi": "Finnish",
    "zh": "Chinese", "es": "Spanish", "nl": "Dutch",
}

# Local road-number badge templates have no English counterpart. They render as
# the bare number, so the English article can just say what the sign says.
TEMPLATE_FALLBACKS = {
    "pl": {
        "Autostrada Polska": "A{1}",
        "Droga Ekspresowa Polska": "S{1}",
        "Droga Krajowa Polska": "DK{1}",
        "Droga Wojewódzka Polska": "DW{1}",
    },
    "ru": {"Табличка-ru": "{1}{2}", "Табличка дороги": "{2}"},
    "uk": {"Табличка-ua": "{1}{2}"},
}

# The category namespace on each wiki, so its category links can be rewritten.
CATEGORY_NS = {
    "de": "Kategorie", "pl": "Kategoria", "ru": "Категория", "uk": "Категорія",
    "fr": "Catégorie", "it": "Categoria", "pt": "Categoria", "fi": "Luokka",
    "es": "Categoría", "nl": "Categorie",
}

SYSTEM_PROMPT = """You translate Hitchwiki articles from {language} into English.

Hitchwiki is a hitchhiking guide, so the register is practical and direct: this \
is one hitchhiker telling another how to get out of a place.

Rules:
- Translate into British English. Use the terms this wiki uses: motorway (not \
highway), service station (not rest stop), slip road, on-ramp, petrol station, \
lift (a ride), lorry, roundabout, junction, city centre.
- Keep local proper names in their own language: place names, service station \
names, street names, road numbers, the names on road signs. A hitchhiker has to \
recognise them on a sign, so do not translate them mid-sentence. Where a place \
has a settled English name, use it (Kyiv, Warsaw, Moscow), but leave street and \
station names alone.
- Names written in a non-Latin script must be transliterated into Latin script \
the way English usually writes them, not left in the original script.
- Keep wiki markup exactly as it is: heading markers (==), list markers (*, #), \
bold/italic quotes, indentation, blank lines. This is wikitext, never Markdown: \
bold is three apostrophes on each side, never **asterisks**, and a heading is \
== written like this == rather than ## like this.
- Placeholders of the form <<<7>>> stand for links, templates, images and HTML \
that must not be touched. Reproduce every placeholder exactly, in a position \
that makes sense in the English sentence. Never add, drop, renumber or alter \
one, and never write anything inside the angle brackets.
- Translate the meaning, not the word order. Write what an English-speaking \
hitchhiker would write. Do not add information, do not omit information, and do \
not add commentary.
- The article's English title is given to you. Where the lead sentence \
introduces the subject, the bolded name in it is that title with any \
parenthesised disambiguator dropped — the title "Ammerland (district)" is \
bolded as \'\'\'Ammerland\'\'\' — and the sentence should follow the form this wiki \
already uses:
    \'\'\'Gudow Nord\'\'\' is a service station along the German motorway A24, ...
    \'\'\'Ammerland\'\'\' is a district in Lower Saxony; its seat is ...
  Do not carry over the source language's framing.
- Reply with the translated wikitext and nothing else."""

VALUE_PROMPT = """You translate a single short value from an infobox field of a \
Hitchwiki article into English.

It is a fragment, not a question and not a topic: a region name, a currency, a \
language, a list of roads. Translate it and reply with the translated fragment \
alone — no sentence, no explanation, no heading, no bullet points, no quotes \
around it, and nothing added in brackets. "śląskie" is "Silesian Voivodeship", \
not "Silesian (Voivodeship)". If it is already English, or is a proper name \
that English keeps as it is, reply with it unchanged. Keep any wiki markup and \
any <<<7>>> placeholders exactly as they are."""

PLACEHOLDER = re.compile(r"<<<(\d+)>>>")
# Letters outside the Latin script, so a label can be recognised as unreadable
# to an English reader even after its link target has been translated.
NON_LATIN = re.compile(r"[\u0370-\u1CFF\u1F00-\u1FFF\u2C00-\uFFFF]")


def api_get(url, params):
    query = urllib.parse.urlencode({**params, "format": "json", "formatversion": "2"})
    with urllib.request.urlopen(f"{url}?{query}", timeout=60) as response:
        return json.load(response)


def list_articles(lang, template):
    """Titles of the main-namespace articles that use a template."""
    namespace = TEMPLATE_NS.get(lang, "Template")
    titles, cont = [], {}
    while True:
        data = api_get(api_url(lang), {
            "action": "query", "list": "embeddedin",
            "eititle": f"{namespace}:{template}", "einamespace": "0",
            "eifilterredir": "nonredirects", "eilimit": "500", **cont,
        })
        titles += [p["title"] for p in data["query"]["embeddedin"]]
        if "continue" not in data:
            return titles
        cont = data["continue"]


def fetch_wikitext(lang, titles):
    """Current wikitext of up to 50 pages at a time."""
    out = {}
    for i in range(0, len(titles), 50):
        data = api_get(api_url(lang), {
            "action": "query", "prop": "revisions", "rvprop": "content",
            "rvslots": "main", "titles": "|".join(titles[i:i + 50]),
        })
        for page in data["query"]["pages"]:
            content = (page.get("revisions") or [{}])[0].get("slots", {}).get("main", {}).get("content")
            if content is not None:
                out[page["title"]] = content
    return out


def load_json(variable):
    """Read one of the JSON files exportTitleMap.php writes."""
    with open(os.environ[variable], encoding="utf-8") as handle:
        return json.load(handle)


class Masker:
    """Replaces wiki structure with placeholders and puts it back."""

    # Order matters: templates may contain links, so they are taken first.
    PATTERNS = [
        re.compile(r"\{\{(?:[^{}]|\{\{[^{}]*\}\})*\}\}", re.S),
        re.compile(r"\[\[[^\[\]]*\]\]"),
        re.compile(r"<(map|rating|gallery|ref|nowiki)\b.*?(?:/>|</\1>)", re.S | re.I),
        re.compile(r"https?://\S+"),
    ]

    # An external link's label is prose and has to be translated; only its URL
    # is untouchable. Masking the whole thing leaves the reader a list of links
    # captioned in a language they came here to avoid.
    EXTERNAL = re.compile(r"\[((?:https?|ftp)://[^\s\]]+)((?:\s[^\]]*)?)\]")

    def __init__(self):
        self.items = []

    def mask(self, text):
        text = self.EXTERNAL.sub(
            lambda m: "[" + self._store_value(m.group(1)) + m.group(2) + "]", text
        )
        for pattern in self.PATTERNS:
            text = pattern.sub(self._store, text)
        return text

    def _store_value(self, value):
        self.items.append(value)
        return f"<<<{len(self.items) - 1}>>>"

    def _store(self, match):
        return self._store_value(match.group(0))

    def unmask(self, text):
        missing = set(range(len(self.items))) - {
            int(n) for n in PLACEHOLDER.findall(text)
        }
        if missing:
            raise ValueError(f"translation dropped placeholders: {sorted(missing)}")
        return PLACEHOLDER.sub(lambda m: self.items[int(m.group(1))], text)


def translate(text, model, key, title=None, prompt=None, retries=4):
    """One chat completion, retried on transport and rate-limit errors."""
    if not text.strip():
        return text
    user = f"English title of this article: {title}\n\n{text}" if title else text
    body = json.dumps({
        "model": model,
        "messages": [
            {"role": "system", "content": prompt or SYSTEM_PROMPT},
            {"role": "user", "content": user},
        ],
    }).encode()
    request = urllib.request.Request(OPENAI_URL, data=body, headers={
        "Authorization": f"Bearer {key}", "Content-Type": "application/json",
    })
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(request, timeout=300) as response:
                return json.load(response)["choices"][0]["message"]["content"].strip()
        except Exception as error:  # noqa: BLE001 - retry anything transient
            if attempt == retries - 1:
                raise
            time.sleep(2 ** attempt * 3)
            print(f"    retry {attempt + 1}: {error}", file=sys.stderr)
    raise AssertionError("unreachable")


# Progressively finer places to cut an article up, used only when a whole
# request has already come back malformed.
SPLITTERS = [
    re.compile(r"(\n\s*\n)"),          # paragraphs
    re.compile(r"(\n)"),                # lines
    re.compile(r"(?<=[.!?])(\s+)"),     # sentences
]


def translate_body(body, model, key, title, splitters=None, prompt=None):
    """Translate an article body, cutting it finer whenever a request comes back
    with placeholders missing.

    A long run of near-identical clauses ("die A99 von X nach Y, die A96 von
    ...") gives the model thirty-odd interchangeable placeholders in one
    sentence, and it occasionally merges two of them. Each retry hands it a
    smaller piece with fewer placeholders to keep straight. The cost is only
    paid for the articles that actually need it.
    """
    if splitters is None:
        splitters = SPLITTERS
    attempts = 1 if splitters else 5
    for attempt in range(attempts):
        masker = Masker()
        try:
            return masker.unmask(
                translate(masker.mask(body), model, key, title=title, prompt=prompt)
            )
        except ValueError as error:
            if splitters:
                print(f"    {error}; splitting further", file=sys.stderr)
                break
            if attempt == attempts - 1:
                # Nothing left to split and it still comes back malformed.
                # One untranslated fragment is a far better outcome than
                # losing the article, and it is visible in the result.
                print(f"    {error}; keeping this fragment untranslated",
                      file=sys.stderr)
                return body

    pieces = splitters[0].split(body)
    if len(pieces) < 2:
        return translate_body(body, model, key, title, splitters[1:], prompt)
    return "".join(
        piece if not piece.strip()
        else translate_body(piece, model, key, title, splitters[1:], prompt)
        for piece in pieces
    )


def split_template_call(text, name):
    """Cut a named template call out of a page.

    The infobox is not always the first thing on the page — articles often open
    with a stub banner — so every top-level call is considered and the one whose
    name matches wins. Returns (call, rest) or (None, text).
    """
    wanted = name.replace("_", " ").strip().lower()
    offset = 0
    while (start := text.find("{{", offset)) != -1:
        depth, i = 0, start
        while i < len(text) - 1:
            pair = text[i:i + 2]
            if pair == "{{":
                depth, i = depth + 1, i + 2
            elif pair == "}}":
                depth, i = depth - 1, i + 2
                if depth == 0:
                    break
            else:
                i += 1
        else:
            return None, text
        call = text[start:i]
        called = call[2:-2].split("|", 1)[0].replace("_", " ").strip().lower()
        if called == wanted:
            return call, text[:start] + text[i:]
        offset = i
    return None, text


def parse_params(call):
    """Named parameters of a template call, splitting only on top-level pipes."""
    inner = call[2:-2]
    parts, current, braces, brackets = [], "", 0, 0
    i = 0
    while i < len(inner):
        pair = inner[i:i + 2]
        if pair in ("{{", "[["):
            braces += pair == "{{"
            brackets += pair == "[["
            current += pair
            i += 2
            continue
        if pair in ("}}", "]]"):
            braces -= pair == "}}"
            brackets -= pair == "]]"
            current += pair
            i += 2
            continue
        if inner[i] == "|" and not braces and not brackets:
            parts.append(current)
            current = ""
            i += 1
            continue
        current += inner[i]
        i += 1
    parts.append(current)

    name = parts.pop(0).strip()
    params = []
    for part in parts:
        if "=" not in part:
            continue
        key, _, value = part.partition("=")
        params.append((key.strip(), value.strip()))
    return name, params


def lookup(titles, target):
    """Map a German title, the way MediaWiki compares them (first letter blind)."""
    target = target.replace("_", " ").strip()
    if target in titles:
        return titles[target], True
    flipped = target[:1].swapcase() + target[1:]
    if flipped in titles:
        return titles[flipped], True
    return None, False


def map_links(text, titles):
    """Point wikilinks at the English articles, or unlink them.

    A target with no English article is still worth linking if it is a place —
    a red link is how a wiki asks for the article to be written. Targets mapped
    to null are German common nouns that English would never have an article
    for, so only the link goes, not the words.
    """
    def one(match):
        target, pipe, label = match.group(1).partition("|")
        target = target.strip()
        english, known = lookup(titles, target)
        if not known:
            return match.group(0)
        if english is None:
            return label if pipe else target
        if pipe and not NON_LATIN.search(label):
            return f"[[{english}|{label}]]"
        # A label in another script would show as that script on the English
        # wiki; the English title is what the reader can actually read.
        return f"[[{english}]]" if english != target or pipe else match.group(0)
    return re.sub(r"\[\[([^\[\]]*)\]\]", one, text)


def english_number(value):
    """1.234.567 or 1 234 567 -> 1,234,567, leaving anything else alone."""
    return re.sub(r"\d{1,3}(?:([.'\u2019\u00a0 ])\d{3})+",
                  lambda m: m.group(0).replace(m.group(1), ","), value)


def map_ratings(text, lang):
    ratings = RATINGS.get(lang, {})
    def one(match):
        english = ratings.get(match.group(1).strip().lower())
        return "{{" + english + "}}" if english else match.group(0)
    return re.sub(r"\{\{\s*([^{}|]+?)\s*\}\}", one, text)


def existing_templates(names):
    """Which of these templates exist on the English wiki."""
    found, names = set(), sorted({n for n in names if n})
    for i in range(0, len(names), 40):
        data = api_get(api_url("en"), {
            "action": "query",
            "titles": "|".join(f"Template:{n}" for n in names[i:i + 40]),
        })
        for page in data["query"].get("pages", []):
            if not page.get("missing"):
                found.add(page["title"].split(":", 1)[1])
    return found


def clean_for_english(text, lang, titles):
    """Remove what only made sense on the source wiki.

    A template call the English wiki cannot resolve renders as a red link in
    the middle of a sentence, and a category named in the source language files
    the article somewhere English readers will never look.
    """
    fallbacks = TEMPLATE_FALLBACKS.get(lang, {})
    called = set(re.findall(r"\{\{\s*([^{}|]+?)\s*[|}]", text))
    known = existing_templates(called - set(fallbacks))

    def one_template(match):
        name = match.group(1).strip()
        if name in known:
            # IsIn's argument is a page name, so it needs the same treatment
            # as a link target or the breadcrumb stays in the source language.
            if name.lower() == "isin" and match.group(2):
                english, is_known = lookup(titles, match.group(2).strip())
                if is_known and english:
                    return "{{IsIn|%s}}" % english
            return match.group(0)
        args = [a.strip() for a in (match.group(2) or "").split("|") if a.strip()]
        pattern = fallbacks.get(name)
        if pattern:
            for n, arg in enumerate(args, start=1):
                pattern = pattern.replace("{%d}" % n, arg)
            return re.sub(r"\{\d+\}", "", pattern)
        # Unknown and unmapped: keep whatever text the arguments carried.
        return " ".join(a for a in args if "=" not in a)

    text = re.sub(r"\{\{\s*([^{}|]+?)\s*(?:\|([^{}]*))?\}\}", one_template, text)

    namespace = CATEGORY_NS.get(lang)
    if namespace:
        def one_category(match):
            english, is_known = lookup(titles, match.group(1).strip())
            return f"[[Category:{english}]]" if is_known and english else ""
        text = re.sub(r"\[\[\s*%s\s*:\s*([^\]|]+?)\s*\]\]" % re.escape(namespace),
                      one_category, text)

    # Markdown bold occasionally slips through despite the instruction.
    return re.sub(r"\*\*([^*\n]+)\*\*", r"'''\1'''", text)


def translate_value(value, model, key):
    """Translate one infobox value, or keep the original if the reply is not one.

    A short fragment handed to a translator can come back as an explanation of
    the topic rather than a translation of the words; length is a good enough
    tell, and the original value is always a safe fallback.
    """
    masker = Masker()
    masked = masker.mask(value)
    if not re.search(r"[^\W\d_]", PLACEHOLDER.sub("", masked), re.UNICODE):
        return value
    try:
        translated = masker.unmask(
            translate(masked, model, key, prompt=VALUE_PROMPT)
        )
    except ValueError:
        return value
    if "\n" in translated or len(translated) > max(3 * len(value), len(value) + 40):
        return value
    return translated


def convert_infobox(call, spec, lang, titles, model, key, source_title=""):
    """Rewrite a local infobox call as its English counterpart.

    The parameter mapping comes from InfoboxMapping, exported as JSON, so the
    renaming rules exist in exactly one place. A parameter marked `skip` is
    local plumbing; one marked `review` holds text in the source language and
    is worth sending through the translator; anything else converts in code.
    """
    _, params = parse_params(call)
    english_name = spec["english"][0] if isinstance(spec["english"], list) else spec["english"]
    lines = ["{{" + english_name]
    for name, value in params:
        rule = spec["params"].get(name)
        if rule is None or not value or rule["policy"] == "skip" or not rule["to"]:
            continue
        target = rule["to"]
        # The English template falls back to the page name, which is the
        # English one; repeating the source-language name would override it.
        if target in ("name", "name_native") and value.strip() == source_title.strip():
            continue

        settled = False
        if target in ("country", "state", "region", "capital", "seat"):
            if value in REGIONS.get(lang, {}):
                value = REGIONS[lang][value]
                settled = True
            translated, known = lookup(titles, value.strip("[]"))
            if known and translated:
                value = f"[[{translated}]]" if value.startswith("[[") else translated
                settled = True
        if rule["convert"] == "number":
            value = english_number(value)
        else:
            value = map_ratings(map_links(value, titles), lang)
            # A value already resolved from a table is English; asking a
            # translator to improve on it only invites embellishment.
            if rule["policy"] == "review" and not settled:
                value = translate_value(value, model, key)
        lines.append(f"|{target} = {clean_for_english(value, lang, titles)}")
    lines.append("}}")
    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--lang", required=True, help='Source wiki, e.g. "uk".')
    parser.add_argument("--template", required=True,
                        help="Local infobox template whose articles to translate.")
    parser.add_argument("--out", required=True)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--limit", type=int)
    parser.add_argument("--workers", type=int, default=8,
                        help="Articles translated in parallel.")
    parser.add_argument("--only", nargs="*", help="Restrict to these source titles.")
    args = parser.parse_args()

    key = os.environ.get("OPENAI_API_KEY")
    if not key:
        sys.exit("OPENAI_API_KEY is not set (it lives in .env).")

    titles = load_json("SHARED_INFOBOX_TITLEMAP")
    mapping = load_json("SHARED_INFOBOX_MAPPING")
    spec = mapping.get(args.template)
    if spec is None:
        sys.exit(f"No mapping for {args.template!r}; known: {', '.join(sorted(mapping))}")

    prompt = SYSTEM_PROMPT.format(
        language=LANGUAGE_NAMES.get(args.lang, args.lang)
    )

    wanted = args.only or list_articles(args.lang, args.template)
    if args.limit:
        wanted = wanted[:args.limit]
    print(f"{len(wanted)} articles use {args.template}", file=sys.stderr)

    done = set()
    if os.path.exists(args.out):
        with open(args.out, encoding="utf-8") as handle:
            done = {json.loads(line)["source_title"] for line in handle if line.strip()}
        print(f"resuming, {len(done)} already translated", file=sys.stderr)

    sources = fetch_wikitext(args.lang, [t for t in wanted if t not in done])

    def render(item):
        title, wikitext = item
        call, body = split_template_call(wikitext, args.template)
        infobox = (convert_infobox(call, spec, args.lang, titles, args.model, key,
                                   source_title=title)
                   if call else "")
        english, _ = lookup(titles, title)
        english = english or title
        translated = translate_body(body, args.model, key, english, prompt=prompt)
        translated = map_ratings(map_links(translated, titles), args.lang)
        translated = clean_for_english(translated, args.lang, titles)
        return {
            "source_title": title,
            "en_title": english,
            "wikitext": (infobox + "\n" + translated.lstrip("\n")).strip() + "\n",
        }

    lock = threading.Lock()
    finished = 0
    with open(args.out, "a", encoding="utf-8") as out:
        with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
            futures = {pool.submit(render, item): item[0]
                       for item in sorted(sources.items())}
            for future in concurrent.futures.as_completed(futures):
                title = futures[future]
                with lock:
                    finished += 1
                    position = f"[{finished}/{len(sources)}]"
                try:
                    row = future.result()
                except Exception as error:  # noqa: BLE001 - report and keep going
                    print(f"{position} FAILED {title}: {error}", file=sys.stderr)
                    continue
                with lock:
                    out.write(json.dumps(row, ensure_ascii=False) + "\n")
                    out.flush()
                print(f"{position} {title} -> {row['en_title']}", file=sys.stderr)


if __name__ == "__main__":
    main()
