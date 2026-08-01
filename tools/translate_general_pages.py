#!/usr/bin/env python3
"""Translate the general-info pages linked from every main page into each language wiki.

The main page of all 34 wikis links to the same handful of introductory
articles — Top tips, First time hitchhiking, Hitchhiker's safety, Where to
hitchhike, Picking up hitchhikers, Hitchhiking races, Roles — plus the
Category:General info landing page. The English wiki owns them; this script
renders each one into the other languages from the English source.

Only prose is translated. Wikilink *targets*, category names, file names,
template names and parameter names, and external URLs are structural and are
preserved verbatim, so a translation can never repoint a link or invent a
template. Link labels, image captions and external-link text are translated,
`[[Etiquette]]` becoming `[[Etiquette|Etikette]]`. Every translation is
validated against the source before it is written, and a page that fails
validation is retried once and then skipped rather than pushed.

The article lands at its translated title, with redirects from the English
title and its English aliases so that cross-wiki links keep resolving, and the
concept is registered in CentralLangLinks' `page_translations` table so the
interlanguage sidebar works.

    tools/translate_general_pages.py plan                 # what is missing where
    tools/translate_general_pages.py translate de         # write out/de/*.json
    tools/translate_general_pages.py translate all -j 6
    tools/translate_general_pages.py push de              # push what was translated
    tools/translate_general_pages.py register             # page_translations rows
"""

import argparse
import concurrent.futures
import json
import os
import re
import subprocess
import sys
import threading
import time
import urllib.parse
import urllib.request

CONTAINER = "hitchwiki-mediawiki"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
I18N_DIR = os.path.join(ROOT, "tools", "main_page_i18n")
OUT_DIR = os.path.join(ROOT, "tools", "general_pages_out")

OPENAI_URL = "https://api.openai.com/v1/chat/completions"
DEFAULT_MODEL = "gpt-5.6-terra"

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

# slot -> (English source title, index in the main page's nav_links, English
# aliases to redirect at the translation). The nav index says which title that
# language's main page already points at: where it is a translated title the
# page must be created under exactly that name, or the main page stays red.
PAGES = {
    "toptips": ("Top tips", 3, ["Top Tips", "Top 10 Tips", "Tips"]),
    "firsttime": ("First time hitchhiking", 4, ["First time", "Virgin hitchhiking"]),
    "safety": ("Hitchhiker's safety", 5, ["Safety", "How to stay safe", "Safe"]),
    "where": ("Where to hitchhike", 6, ["Where", "Where to start"]),
    "picking": ("Picking up hitchhikers", 7, ["How to pick up hitchhikers", "Driver"]),
    "races": ("Hitchhiking races", 8, ["Races", "Race"]),
    "roles": ("Roles", 9, []),
    "geninfo": ("Category:General info", 1, []),
    "portal": ("Template:Communityportal", None, []),
}
SLOTS = list(PAGES)

# Slots whose title is fixed by the namespace rather than translated.
FIXED_TITLE = {"geninfo": "Category:General info", "portal": "Template:Communityportal"}

SUMMARY = "Translated from the English wiki ({model}), see [[:en:{source}]]"


# --------------------------------------------------------------------------
# wiki access
# --------------------------------------------------------------------------

def api(lang, params, retries=5):
    """Query the wiki API. Retried: php-fpm refuses connections under load."""
    params = dict(params, format="json")
    url = f"http://localhost/{lang}/api.php?" + urllib.parse.urlencode(params)
    last = None
    for attempt in range(retries):
        r = subprocess.run(["docker", "exec", CONTAINER, "curl", "-s", url],
                           capture_output=True, text=True)
        if r.returncode == 0:
            try:
                return json.loads(r.stdout)
            except json.JSONDecodeError as e:
                last = f"{e}: {r.stdout[:200]}"
        else:
            last = f"curl exit {r.returncode}: {r.stderr.strip()}"
        time.sleep(2 * (attempt + 1))
    raise RuntimeError(f"{lang} API failed after {retries} tries: {last}")


def get_wikitext(lang, title):
    return subprocess.run(
        ["docker", "exec", CONTAINER, "php", "/var/www/html/maintenance/run.php",
         "getText", f"--wiki={lang}", title],
        capture_output=True, text=True, check=True,
    ).stdout


def put_wikitext(lang, title, text, summary, create_only=False):
    cmd = ["docker", "exec", "-i", CONTAINER, "php",
           "/var/www/html/maintenance/run.php", "edit", f"--wiki={lang}",
           "--bot", "--summary", summary]
    if create_only:
        cmd.append("--createonly")
    cmd.append(title)
    r = subprocess.run(cmd, input=text, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"{lang}:{title}: {r.stderr.strip() or r.stdout.strip()}")
    return r.stdout.strip()


def page_state(lang, titles):
    """{requested title: (resolved title, byte length or None if missing)}."""
    state = {}
    for i in range(0, len(titles), 40):
        chunk = titles[i:i + 40]
        r = api(lang, {"action": "query", "titles": "|".join(chunk),
                       "prop": "info", "redirects": 1})
        q = r["query"]
        norm = {n["from"]: n["to"] for n in q.get("normalized", [])}
        redir = {x["from"]: x["to"] for x in q.get("redirects", [])}
        by_title = {}
        for p in q["pages"].values():
            by_title[p["title"]] = None if "missing" in p else p.get("length", 0)
        for t in chunk:
            resolved = norm.get(t, t)
            resolved = redir.get(resolved, resolved)
            state[t] = (resolved, by_title.get(resolved))
    return state


def is_missing(lang, title):
    """True when no page of that exact title exists (redirects are not followed)."""
    r = api(lang, {"action": "query", "titles": title, "prop": "info"})
    return all("missing" in p for p in r["query"]["pages"].values())


def nav_titles(lang):
    """The link targets this language's main page uses, by nav slot index."""
    with open(os.path.join(I18N_DIR, f"{lang}.json"), encoding="utf-8") as f:
        data = json.load(f)
    links = re.findall(r"\[\[([^\]]+)\]\]", data["nav_links"])
    return [l.split("|")[0].strip().lstrip(":") for l in links]


def wanted_title(lang, slot):
    """Where this slot's page must live on this wiki, if that is already decided.

    A main page that links to a translated title pins the page to it. A main
    page still linking to the English title leaves the choice to the
    translation, and gets a redirect from the English title.
    """
    source, idx, aliases = PAGES[slot]
    nav = nav_titles(lang)
    target = nav[idx] if idx is not None and idx < len(nav) else None
    if slot in FIXED_TITLE:
        # he/it/ro/ru name the category in their own language; the rest use the
        # English key that the articles' [[Category:General info]] points at.
        fixed = FIXED_TITLE[slot]
        return target if target and target != fixed else fixed
    if target is None:
        return None
    return None if target in {source, *aliases} else target


# --------------------------------------------------------------------------
# planning
# --------------------------------------------------------------------------

def plan(langs, slots):
    work = {}
    for lang in langs:
        nav = nav_titles(lang)
        titles, wants = [], {}
        for slot in slots:
            source, idx, _ = PAGES[slot]
            want = wanted_title(lang, slot)
            probe = want or (nav[idx] if idx is not None else source)
            wants[slot] = (want, probe)
            titles.append(probe)
        state = page_state(lang, titles)
        for slot in slots:
            want, probe = wants[slot]
            resolved, length = state[probe]
            if length is None:
                work.setdefault(lang, {})[slot] = want
    return work


# --------------------------------------------------------------------------
# translation
# --------------------------------------------------------------------------

SYSTEM_PROMPT = """\
You translate MediaWiki wikitext for Hitchwiki, a hitchhiking wiki, from English \
into {language}. You are translating the article "{source}".

Return ONLY the translated wikitext. No preamble, no code fences, no commentary.

Preserve every structural element exactly as it appears in the source:

* Wikilink TARGETS are never translated — they are keys into the English wiki. \
Translate only the label. `[[Etiquette]]` becomes `[[Etiquette|<translated word>]]`; \
`[[Top tips|Top Tips]]` becomes `[[Top tips|<translated label>]]`. Never change, \
reorder, drop or invent a target, and never translate a target's spelling.
* `[[Category:...]]`, `[[File:...]]` and `[[Image:...]]` names stay byte-identical. \
For files, translate the caption (the text after the last `|`) but keep sizing and \
positioning options such as `thumb`, `right`, `250px` untouched.
* Template calls keep their name and their parameter names: `{{{{foo|bar=baz}}}}` \
keeps `foo` and `bar`. Translate parameter values that are prose.
* External links keep their URL exactly; translate the label after the space.
* Keep the heading levels, list markers, tables, HTML tags, `<gallery>` blocks, \
bold/italic quotes, and the order of every section and list item.
* Do not add, remove or merge sentences, sections or list items. Translate all of \
it — no summarising, no "...".
* Leave proper nouns, place names, event names, usernames and signatures alone \
unless {language} has an established name for them.

Write natural, idiomatic {language} in the informal register hitchhikers use with \
each other (second person singular where the language distinguishes it). Use the \
established {language} hitchhiking vocabulary."""

USER_PROMPT = """\
Translate this wikitext into {language}.

First line of your reply must be exactly:
TITLE: <the {language} page title for this article>

then a blank line, then the translated wikitext.

{title_hint}
---
{text}"""

_lock = threading.Lock()


def openai_key():
    for line in open(os.path.join(ROOT, ".env"), encoding="utf-8"):
        if line.startswith("OPENAI_API_KEY="):
            return line.split("=", 1)[1].strip().strip("'\"")
    raise SystemExit("OPENAI_API_KEY not found in .env")


def call_openai(key, model, system, user, retries=5):
    body = json.dumps({
        "model": model,
        "messages": [{"role": "system", "content": system},
                     {"role": "user", "content": user}],
    }).encode()
    last = None
    for attempt in range(retries):
        req = urllib.request.Request(
            OPENAI_URL, data=body,
            headers={"Authorization": f"Bearer {key}",
                     "Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=900) as r:
                return json.load(r)["choices"][0]["message"]["content"]
        except Exception as e:  # rate limits and transient 5xx
            last = e
            time.sleep(min(60, 5 * 2 ** attempt))
    raise RuntimeError(f"OpenAI call failed: {last}")


# Structure that must survive translation unchanged. Link targets are read from
# every `[[` in the text rather than from whole `[[...]]` pairs, so that a link
# nested inside an image caption is seen too.
LINK_RE = re.compile(r"\[\[\s*([^\[\]|#\n]+)")
TEMPLATE_RE = re.compile(r"\{\{\s*([^}|<>\n]+?)\s*(?:\||\}\})")
URL_RE = re.compile(r"https?://[^\s\]|}<]+")

# Languages that say the same thing in far fewer characters, so the
# short-translation heuristic has to be looser for them.
DENSE_SCRIPTS = {"zh", "ja"}


def norm_target(t):
    t = t.strip().replace("_", " ")
    return (t[:1].upper() + t[1:]) if t else t


def norm_url(u):
    return u.rstrip(".,;:!?)")


def structure(text):
    links = [norm_target(m) for m in LINK_RE.findall(text)]
    is_file = lambda l: l.lower().startswith(("file:", "image:", "datei:"))
    return {
        "links": sorted(l for l in links if not is_file(l)),
        "files": sorted(l.split(":", 1)[1].strip() for l in links if is_file(l)),
        "templates": sorted(norm_target(t) for t in TEMPLATE_RE.findall(text)),
        "urls": sorted(norm_url(u) for u in URL_RE.findall(text)),
    }


def validate(source, translated, lang=None):
    """Return a list of human-readable structural differences."""
    a, b = structure(source), structure(translated)
    problems = []
    for kind in ("links", "files", "templates", "urls"):
        missing = [x for x in a[kind] if x not in b[kind]]
        added = [x for x in b[kind] if x not in a[kind]]
        if missing:
            problems.append(f"{kind} dropped or renamed: {missing[:8]}")
        if added:
            problems.append(f"{kind} invented: {added[:8]}")
    src_heads = len(re.findall(r"^=+.*=+\s*$", source, re.M))
    out_heads = len(re.findall(r"^=+.*=+\s*$", translated, re.M))
    if src_heads != out_heads:
        problems.append(f"{src_heads} headings in source, {out_heads} in translation")
    floor = 0.2 if lang in DENSE_SCRIPTS else 0.4
    if len(translated) < floor * len(source):
        problems.append(
            f"translation is {len(translated)} chars against {len(source)} in the source")
    return problems


def split_title(reply):
    reply = reply.strip()
    if reply.startswith("```"):
        reply = re.sub(r"^```[a-z]*\n", "", reply)
        reply = re.sub(r"\n```$", "", reply)
    m = re.match(r"TITLE:\s*(.+?)\n(.*)$", reply, re.S)
    if not m:
        return None, reply
    return m.group(1).strip(), m.group(2).lstrip("\n")


def translate_one(key, model, lang, slot, want_title, source_text):
    source, _, _ = PAGES[slot]
    language = LANGUAGE_NAMES[lang]
    if want_title:
        hint = f'The page title is already fixed: reply with exactly "TITLE: {want_title}".'
    elif slot in FIXED_TITLE:
        hint = f'The page title is fixed: reply with exactly "TITLE: {FIXED_TITLE[slot]}".'
    else:
        hint = ("Give the article a natural title in the target language, without "
                "any namespace prefix.")
    system = SYSTEM_PROMPT.format(language=language, source=source)
    user = USER_PROMPT.format(language=language, title_hint=hint, text=source_text)

    for attempt in range(2):
        reply = call_openai(key, model, system, user)
        title, text = split_title(reply)
        problems = validate(source_text, text, lang)
        if not problems:
            break
        user = (USER_PROMPT.format(language=language, title_hint=hint, text=source_text)
                + "\n\nYour previous attempt broke the structure:\n- "
                + "\n- ".join(problems)
                + "\n\nTranslate again, keeping every link target, file name, "
                  "template name and URL byte-identical to the source.")
    else:
        return None, text, problems

    if want_title:
        title = want_title
    elif slot in FIXED_TITLE:
        title = FIXED_TITLE[slot]
    if not title:
        return None, text, ["model returned no TITLE line"]
    return title, text, []


def cmd_translate(args):
    key = openai_key()
    langs = LANGS if args.lang == "all" else args.lang.split(",")
    slots = args.slots.split(",") if args.slots else SLOTS
    work = plan(langs, slots)
    if not work:
        print("nothing missing")
        return 0

    sources = {slot: get_wikitext("en", PAGES[slot][0]) for slot in slots}
    jobs = [(lang, slot, want) for lang, d in work.items() for slot, want in d.items()]
    print(f"{len(jobs)} pages to translate across {len(work)} wikis", file=sys.stderr)

    def run(job):
        lang, slot, want = job
        path = os.path.join(OUT_DIR, lang, f"{slot}.json")
        if os.path.exists(path) and not args.force:
            return lang, slot, "cached"
        title, text, problems = translate_one(key, args.model, lang, slot, want,
                                              sources[slot])
        if problems:
            with _lock:
                print(f"  !! {lang}/{slot}: {'; '.join(problems)}", file=sys.stderr)
            return lang, slot, "failed"
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            json.dump({"lang": lang, "slot": slot, "source": PAGES[slot][0],
                       "title": title, "model": args.model, "text": text},
                      f, ensure_ascii=False, indent=1)
        return lang, slot, f"ok ({len(text)}b) -> {title}"

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        for lang, slot, status in pool.map(run, jobs):
            print(f"{lang}/{slot}: {status}")
    return 0


# --------------------------------------------------------------------------
# pushing
# --------------------------------------------------------------------------

def cmd_push(args):
    langs = LANGS if args.lang == "all" else args.lang.split(",")
    slots = args.slots.split(",") if args.slots else SLOTS
    # Each edit.php invocation boots MediaWiki from scratch, so a serial push of
    # a few hundred pages takes hours. The wikis have separate databases; one
    # worker per language is safe and turns that into minutes.
    if args.jobs > 1 and len(langs) > 1:
        with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
            futures = {pool.submit(push_lang, lang, slots, args): lang for lang in langs}
            for fut in concurrent.futures.as_completed(futures):
                lang = futures[fut]
                try:
                    fut.result()
                except Exception as e:
                    print(f"{lang}: FAILED {e}", file=sys.stderr)
        return 0
    for lang in langs:
        push_lang(lang, slots, args)
    return 0


def push_lang(lang, slots, args):
    # Output is collected and printed in one go, so parallel workers do not
    # interleave a language's lines with another's.
    lines = []
    for slot in slots:
        path = os.path.join(OUT_DIR, lang, f"{slot}.json")
        if not os.path.exists(path):
            continue
        with open(path, encoding="utf-8") as f:
            page = json.load(f)
        title, text = page["title"], page["text"]
        summary = SUMMARY.format(model=page["model"], source=page["source"])

        _, length = page_state(lang, [title])[title]
        if length is not None and not args.force:
            lines.append(f"{lang}/{slot}: {title} exists ({length}b), skipping")
            continue
        if args.dry_run:
            lines.append(f"{lang}/{slot}: would write {title} ({len(text)}b)")
            continue
        put_wikitext(lang, title, text, summary)
        lines.append(f"{lang}/{slot}: wrote {title} ({len(text)}b)")

        # Redirects from the English title and its aliases, so links written on
        # any wiki against the English key keep resolving.
        source, _, aliases = PAGES[slot]
        if slot in FIXED_TITLE:
            continue
        for alias in [source] + aliases:
            if alias == title:
                continue
            resolved, alen = page_state(lang, [alias])[alias]
            # An alias that already resolves to a live page is left alone; a
            # redirect left pointing at a page that was never written (zh's
            # "First time hitchhiking") is repointed at the translation.
            if alen is not None or (resolved == alias and not is_missing(lang, alias)):
                continue
            put_wikitext(lang, alias, f"#REDIRECT [[{title}]]\n",
                         f"Redirect to [[{title}]]")
            lines.append(f"{lang}/{slot}:   redirect {alias} -> {title}")
    with _lock:
        print("\n".join(lines), flush=True)
    return 0


# --------------------------------------------------------------------------
# interlanguage registration
# --------------------------------------------------------------------------

def cmd_register(args):
    """Point page_translations at whatever each wiki actually has right now."""
    slots = args.slots.split(",") if args.slots else SLOTS
    for slot in slots:
        source, idx, _ = PAGES[slot]
        if slot == "portal":
            continue
        links = []
        for lang in LANGS:
            nav = nav_titles(lang)
            probe = wanted_title(lang, slot) or (nav[idx] if idx is not None else source)
            resolved, length = page_state(lang, [probe])[probe]
            if length is None:
                continue
            links.append(f"{lang}:{resolved}")
        if not links:
            print(f"{source}: no translations found")
            continue
        cmd = ["docker", "exec", CONTAINER, "php",
               "/var/www/html/maintenance/run.php",
               "/var/www/html/extensions/CentralLangLinks/maintenance/setTranslations.php",
               "--wiki=en", "--concept", source]
        for l in links:
            cmd += ["--link", l]
        if args.dry_run:
            print(f"{source}: would register {len(links)} languages: {links}")
            continue
        r = subprocess.run(cmd, capture_output=True, text=True)
        if r.returncode != 0:
            print(f"{source}: FAILED {r.stderr.strip()}", file=sys.stderr)
        else:
            print(f"{source}: registered {len(links)} languages")
    return 0


def cmd_plan(args):
    langs = LANGS if args.lang == "all" else args.lang.split(",")
    slots = args.slots.split(",") if args.slots else SLOTS
    work = plan(langs, slots)
    total = 0
    for lang in langs:
        missing = work.get(lang, {})
        total += len(missing)
        marks = " ".join(s if s in missing else f"({s})" for s in slots)
        print(f"{lang}: {len(missing)} missing | {marks}")
    print(f"\n{total} pages missing in total")
    return 0


def main():
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)

    def common(sp):
        sp.add_argument("lang", nargs="?", default="all")
        sp.add_argument("--slots", help="comma-separated subset of " + ",".join(SLOTS))
        return sp

    common(sub.add_parser("plan")).set_defaults(func=cmd_plan)

    t = common(sub.add_parser("translate"))
    t.add_argument("--model", default=DEFAULT_MODEL)
    t.add_argument("-j", "--jobs", type=int, default=4)
    t.add_argument("--force", action="store_true", help="re-translate cached pages")
    t.set_defaults(func=cmd_translate)

    u = common(sub.add_parser("push"))
    u.add_argument("-j", "--jobs", type=int, default=6, help="languages in parallel")
    u.add_argument("--dry-run", action="store_true")
    u.add_argument("--force", action="store_true", help="overwrite existing pages")
    u.set_defaults(func=cmd_push)

    r = common(sub.add_parser("register"))
    r.add_argument("--dry-run", action="store_true")
    r.set_defaults(func=cmd_register)

    args = p.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
