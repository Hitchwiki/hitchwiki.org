#!/usr/bin/env python3
"""Translate the English wiki's country and city articles into the other wikis.

The English wiki has ~2,750 place articles; most other wikis have almost none,
so a Finn or a Lithuanian searching in their own language finds nothing. This
fills that in from English, in the order a reader is most likely to want:

    countries      every European country -> every European wiki
    concepts       the articles explaining hitchhiking itself -> every wiki
    home-cities    each language's own countries' cities -> that wiki
    major-cities   the cities the rest of the wiki links to most -> every wiki

A page is only ever created, never overwritten: if a wiki already has the
article — under the English title, or under a title `page_translations` knows —
it is left alone, because a human wrote it.

Two things make a translated place article different from its English source:

* It carries **no infobox**. `SharedInfobox` renders the English counterpart's
  box on it, so translating the box would mean two sources of truth for a
  population figure. The `{{Infobox …}}` call is cut before translation and the
  article is registered in `page_translations`, which is what SharedInfobox
  matches on.
* It ends with **`{{hwen:Ai-enhanced}}`**, the banner the English wiki already
  uses on 254 machine-translated pages, telling readers to verify and inviting
  them to correct it. It is transcluded from `en` rather than copied to each
  wiki, so rewording it reaches all 34 at once.

Only prose is translated. Wikilink targets, category and file names, template
names and parameters, and URLs are structural: they stay byte-identical, so a
translation can never repoint a link or invent a template, and the English
title stays the key that page_translations and cross-wiki links resolve
against. Every translation is validated against its source and a page that
fails is retried and then skipped rather than pushed.

    tools/translate_place_articles.py plan --tier countries
    tools/translate_place_articles.py batch submit --tier countries   # 50% cheaper
    tools/translate_place_articles.py batch poll
    tools/translate_place_articles.py batch collect
    tools/translate_place_articles.py translate --tier countries -j 12  # live instead
    tools/translate_place_articles.py bootstrap --tier countries        # templates
    tools/translate_place_articles.py push --tier countries
    tools/translate_place_articles.py register --tier countries
"""

import argparse
import concurrent.futures
import io
import json
import os
import re
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from place_corpus import (  # noqa: E402
    CONTAINER, EUROPEAN, EUROPEAN_COUNTRIES, HOME_COUNTRIES, LANGS,
    LANGUAGE_NAMES, ROOT, api, cached_wikitext, load_index, page_lengths, slug,
)

OUT_DIR = os.path.join(ROOT, "tools", "place_pages_out")
BATCH_DIR = os.path.join(ROOT, "tools", "place_batches")
OPENAI = "https://api.openai.com/v1"
DEFAULT_MODEL = "gpt-5.6-terra"

SUMMARY = "Translated from the English wiki ({model}), see [[:en:{source}]]"

# What counts as a "major" city, in two halves, because one measure alone gets
# it wrong. Inbound links find the junctions the rest of the wiki routes
# through — but this wiki grew in Germany, so on its own that list is Würzburg
# and Koblenz and no Athens. National capitals fill in the cities a reader has
# heard of. The union is what a reader of any language is most likely to look up.
MAJOR_BY_INBOUND = 150

TIERS = ("countries", "concepts", "home-cities", "major-cities")


# --------------------------------------------------------------------------
# what to translate where
# --------------------------------------------------------------------------

def tier_targets(idx, tier, langs=None, top=None):
    """Every (lang, english title) the tier covers, whatever each wiki has.

    `push`, `bootstrap` and `register` work from this rather than from
    `tier_jobs`, because `have` goes stale the moment a push starts: rebuilding
    the index mid-run would otherwise make `register` skip the very pages the
    push had just created, leaving them with no `page_translations` row and so
    no infobox. Both commands check the live wiki anyway before writing.
    """
    if tier == "countries":
        targets = [c for c in EUROPEAN_COUNTRIES if c in set(idx["countries"])]
        return [(l, t) for l in (langs or EUROPEAN) for t in targets]
    if tier == "concepts":
        targets = idx.get("concepts", [])
        return [(l, t) for l in (langs or LANGS) for t in targets]
    if tier == "home-cities":
        out = []
        for lang in (langs or LANGS):
            home = set(HOME_COUNTRIES.get(lang, []))
            out += [(lang, c) for c in
                    sorted(c for c, k in idx["city_country"].items() if k in home)]
        return out
    if tier == "major-cities":
        targets = major_cities(idx, top)
        return [(l, t) for l in (langs or LANGS) for t in targets]
    raise SystemExit(f"unknown tier {tier}")


def tier_jobs(idx, tier, langs=None, top=None):
    """[(lang, english title)] the tier covers that the wiki did not have.

    Used for deciding what to *translate*. Writing commands use
    `tier_targets` instead — see the note there.
    """
    have = idx["have"]
    return [(l, t) for l, t in tier_targets(idx, tier, langs, top)
            if t not in have.get(l, {})]


CAPITAL_RE = re.compile(r"\|\s*capital\s*=\s*(.+)", re.I)

_major = []


def major_cities(idx, top=None):
    """The most-linked-to cities, plus every national capital that has an
    article. Cached: it is recomputed for each of 33 languages otherwise."""
    top = top or MAJOR_BY_INBOUND
    if _major and _major[0] == top:
        return _major[1]
    cities = set(idx["cities"])
    inbound = idx["inbound"]
    ranked = sorted(idx["cities"],
                    key=lambda c: (-inbound.get(c, 0), c))[:top]
    capitals = set()
    for country in idx["countries"]:
        m = CAPITAL_RE.search(cached_wikitext(country))
        if not m:
            continue
        # `|capital = [[Bern]]`, or a bare name, or a list of several.
        names = re.findall(r"\[\[([^\]|]+)", m.group(1)) or [m.group(1).strip()]
        capitals |= {n.strip() for n in names if n.strip() in cities}
    del _major[:]
    _major.extend([top, sorted(set(ranked) | capitals)])
    return _major[1]


def out_path(lang, title):
    return os.path.join(OUT_DIR, lang, slug(title) + ".json")


def pending(idx, tier, langs=None, force=False, limit=None, top=None):
    """Jobs with nothing translated for them yet."""
    jobs = [(l, t) for l, t in tier_jobs(idx, tier, langs, top)
            if force or not os.path.exists(out_path(l, t))]
    return jobs[:limit] if limit else jobs


# --------------------------------------------------------------------------
# preparing the English source
# --------------------------------------------------------------------------

INFOBOX_RE = re.compile(r"\{\{\s*infobox\b", re.I)


def strip_infobox(text):
    """Remove every `{{Infobox …}}` call, matching braces so a nested template
    or a `<map/>` tag inside it does not end the match early."""
    out, i = [], 0
    while True:
        m = INFOBOX_RE.search(text, i)
        if not m:
            out.append(text[i:])
            break
        out.append(text[i:m.start()])
        depth, j = 0, m.start()
        while j < len(text):
            if text.startswith("{{", j):
                depth += 1
                j += 2
            elif text.startswith("}}", j):
                depth -= 1
                j += 2
                if depth == 0:
                    break
            else:
                j += 1
        i = j
    return re.sub(r"\n{3,}", "\n\n", "".join(out).lstrip("\n"))


AI_TAG_RE = re.compile(r"\{\{\s*ai-enhanced\s*\}\}\s*", re.I)


def prepare_source(title):
    """The English article as the model should see it: no infobox, and no
    existing {{Ai-enhanced}} banner (we append our own on push)."""
    return AI_TAG_RE.sub("", strip_infobox(cached_wikitext(title))).strip() + "\n"


# --------------------------------------------------------------------------
# prompting
# --------------------------------------------------------------------------

SYSTEM_PROMPT = """\
You translate MediaWiki wikitext for Hitchwiki, a hitchhiking wiki, from English \
into {language}. You are translating the article about the place "{source}".

Return ONLY the translated wikitext. No preamble, no code fences, no commentary.

Preserve every structural element exactly as it appears in the source:

* Wikilink TARGETS are never translated — they are keys into the English wiki. \
Translate only the label. `[[Berlin]]` becomes `[[Berlin|<translated name>]]` only \
if {language} spells the place differently, otherwise leave `[[Berlin]]` alone. \
`[[Munich|München]]` keeps the target `Munich`. Never change, reorder, drop or \
invent a target.
* `[[Category:...]]`, `[[File:...]]` and `[[Image:...]]` names stay byte-identical. \
For files, translate the caption (the text after the last `|`) but keep sizing and \
positioning options such as `thumb`, `right`, `250px` untouched.
* Template calls keep their name and their parameter names and values: \
`{{{{Coords|54.68|25.28}}}}`, `{{{{IsIn|Lithuania}}}}`, `{{{{Ade|9}}}}`, \
`{{{{European Route Number|272}}}}`, `{{{{stub}}}}` and `{{{{nomadwiki|X}}}}` are \
copied through unchanged. Translate a parameter value only when it is prose.
* `{{{{FULLPAGENAME}}}}` and other magic words are copied through unchanged.
* External links keep their URL exactly; translate the label after the space.
* Keep the heading levels, list markers, tables, HTML tags, bold/italic quotes, \
and the order of every section and list item.
* Do not add, remove or merge sentences, sections or list items. Translate all of \
it — no summarising, no "...".

This is practical hitchhiking information: street names, bus and tram numbers, \
stop names, motorway numbers, petrol station brands and quoted local signage are \
what a reader navigates by, so keep them in their original form. Where the source \
already gives a local-language name in quotes or italics, keep it and translate \
the surrounding explanation.

Write natural, idiomatic {language} in the informal register hitchhikers use with \
each other (second person singular where the language distinguishes it). Use the \
established {language} hitchhiking vocabulary."""

USER_PROMPT = """\
Translate this Hitchwiki article into {language}.

First line of your reply must be exactly:
TITLE: <the {language} name of this place, as the page should be titled>

then a blank line, then the translated wikitext. If {language} uses the same \
spelling as English for this place, repeat the English name on the TITLE line.

---
{text}"""


def build_messages(lang, title, text):
    language = LANGUAGE_NAMES[lang]
    return [
        {"role": "system", "content": SYSTEM_PROMPT.format(
            language=language, source=title)},
        {"role": "user", "content": USER_PROMPT.format(
            language=language, text=text)},
    ]


# --------------------------------------------------------------------------
# OpenAI
# --------------------------------------------------------------------------

_lock = threading.Lock()


def openai_key():
    for line in open(os.path.join(ROOT, ".env"), encoding="utf-8"):
        if line.startswith("OPENAI_API_KEY="):
            return line.split("=", 1)[1].strip().strip("'\"")
    raise SystemExit("OPENAI_API_KEY not found in .env")


def openai_request(key, path, data=None, method=None, headers=None, timeout=900):
    req = urllib.request.Request(
        f"{OPENAI}{path}", data=data, method=method,
        headers={"Authorization": f"Bearer {key}", **(headers or {})})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def openai_json(key, path, payload=None, method=None):
    body = json.dumps(payload).encode() if payload is not None else None
    head = {"Content-Type": "application/json"} if body else {}
    return json.loads(openai_request(key, path, body, method, head))


def call_chat(key, model, messages, retries=5):
    body = json.dumps({"model": model, "messages": messages}).encode()
    last = None
    for attempt in range(retries):
        try:
            r = openai_request(key, "/chat/completions", body, None,
                               {"Content-Type": "application/json"})
            return json.loads(r)["choices"][0]["message"]["content"]
        except Exception as e:  # rate limits and transient 5xx
            last = e
            time.sleep(min(90, 5 * 2 ** attempt))
    raise RuntimeError(f"OpenAI call failed: {last}")


# --------------------------------------------------------------------------
# validation
# --------------------------------------------------------------------------

LINK_RE = re.compile(r"\[\[\s*([^\[\]|#\n]+)")
TEMPLATE_RE = re.compile(r"\{\{\s*([^}|<>\n]+?)\s*(?:\||\}\})")
URL_RE = re.compile(r"https?://[^\s\]|}<]+")
DENSE_SCRIPTS = {"zh", "ja"}


def norm_target(t):
    t = t.strip().replace("_", " ")
    return (t[:1].upper() + t[1:]) if t else t


def norm_url(u):
    """Where a URL really ends.

    The regex runs to the next space, which in Chinese and Japanese is most of
    a paragraph: those scripts put no space after a closing bracket, so
    `[https://x.example ...]（。在那里…` came back as one enormous "invented"
    URL and the translation was rejected for it. Nothing above U+2000 —
    CJK, kana, fullwidth punctuation, typographic quotes — belongs in the URLs
    this wiki actually uses, so the URL stops at the first such character.
    Accented Latin, which does appear in them, is below that and survives.
    """
    for i, ch in enumerate(u):
        if ord(ch) >= 0x2000:
            u = u[:i]
            break
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
    if not translated or not translated.strip():
        return ["empty translation"]
    a, b = structure(source), structure(translated)
    problems = []
    for kind in ("links", "files", "templates", "urls"):
        missing = [x for x in a[kind] if x not in b[kind]]
        added = [x for x in b[kind] if x not in a[kind]]
        if missing:
            problems.append(f"{kind} dropped or renamed: {missing[:6]}")
        if added:
            problems.append(f"{kind} invented: {added[:6]}")
    src_heads = len(re.findall(r"^=+.*=+\s*$", source, re.M))
    out_heads = len(re.findall(r"^=+.*=+\s*$", translated, re.M))
    if src_heads != out_heads:
        problems.append(f"{src_heads} headings in source, {out_heads} in translation")
    floor = 0.2 if lang in DENSE_SCRIPTS else 0.4
    if len(translated) < floor * len(source):
        problems.append(f"translation is {len(translated)}b against {len(source)}b source")
    return problems


def split_title(reply):
    reply = (reply or "").strip()
    if reply.startswith("```"):
        reply = re.sub(r"^```[a-z]*\n", "", reply)
        reply = re.sub(r"\n```\s*$", "", reply)
    m = re.match(r"TITLE:\s*(.+?)\n(.*)$", reply, re.S)
    if not m:
        return None, reply
    return m.group(1).strip().strip("'\""), m.group(2).lstrip("\n")


def save(lang, title, model, page_title, text):
    path = out_path(lang, title)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump({"lang": lang, "source": title, "title": page_title,
                   "model": model, "text": text}, f, ensure_ascii=False, indent=1)


# --------------------------------------------------------------------------
# live translation
# --------------------------------------------------------------------------

def cmd_translate(args):
    idx = load_index()
    langs = None if args.lang == "all" else args.lang.split(",")
    jobs = pending(idx, args.tier, langs, args.force, args.limit, args.top)
    if not jobs:
        print("nothing to translate")
        return 0
    key = openai_key()
    print(f"{len(jobs)} articles", file=sys.stderr)
    done = [0]

    def run(job):
        lang, title = job
        source = prepare_source(title)
        messages = build_messages(lang, title, source)
        for attempt in range(2):
            reply = call_chat(key, args.model, messages)
            page_title, text = split_title(reply)
            problems = validate(source, text, lang)
            if not problems and page_title:
                save(lang, title, args.model, page_title, text)
                status = f"ok -> {page_title}"
                break
            messages = messages[:2] + [
                {"role": "assistant", "content": reply},
                {"role": "user", "content":
                 "That broke the structure:\n- " + "\n- ".join(
                     problems or ["no TITLE line"]) +
                 "\n\nTranslate the article again from the original, keeping every "
                 "link target, file name, template name and URL byte-identical, and "
                 "starting with the TITLE line."}]
        else:
            status = "FAILED: " + "; ".join(problems or ["no TITLE line"])
        with _lock:
            done[0] += 1
            print(f"[{done[0]}/{len(jobs)}] {lang}/{title}: {status}", flush=True)

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        list(pool.map(run, jobs))
    return 0


# --------------------------------------------------------------------------
# batch translation
#
# The Batch API takes the whole tier in one file, runs it with no rate limit to
# fight, and costs half of what the same calls cost live. Thousands of long
# articles is exactly what it is for; the live path above stays for retries and
# for one-off pages.
# --------------------------------------------------------------------------

def batch_state_path(name):
    return os.path.join(BATCH_DIR, name + ".json")


def upload_file(key, name, content):
    """Multipart upload to /v1/files. urllib has no multipart helper."""
    boundary = "----hitchwiki" + str(int(time.time() * 1000))
    buf = io.BytesIO()
    def part(headers, body):
        buf.write(f"--{boundary}\r\n{headers}\r\n\r\n".encode())
        buf.write(body)
        buf.write(b"\r\n")
    part('Content-Disposition: form-data; name="purpose"', b"batch")
    part(f'Content-Disposition: form-data; name="file"; filename="{name}"\r\n'
         f"Content-Type: application/jsonl", content)
    buf.write(f"--{boundary}--\r\n".encode())
    return json.loads(openai_request(
        key, "/files", buf.getvalue(), None,
        {"Content-Type": f"multipart/form-data; boundary={boundary}"}))


def cmd_batch_submit(args):
    idx = load_index()
    langs = None if args.lang == "all" else args.lang.split(",")
    jobs = pending(idx, args.tier, langs, args.force, args.limit, args.top)
    if not jobs:
        print("nothing to translate")
        return 0
    key = openai_key()
    os.makedirs(BATCH_DIR, exist_ok=True)

    # One batch per chunk: the API caps a batch at 50,000 requests and 200 MB,
    # and a smaller chunk starts returning results sooner.
    chunks = [jobs[i:i + args.chunk] for i in range(0, len(jobs), args.chunk)]
    stamp = time.strftime("%Y%m%d-%H%M%S")
    for n, chunk in enumerate(chunks, 1):
        lines, index = [], {}
        for lang, title in chunk:
            cid = f"{lang}|{slug(title)}"
            index[cid] = [lang, title]
            lines.append(json.dumps({
                "custom_id": cid,
                "method": "POST",
                "url": "/v1/chat/completions",
                "body": {"model": args.model,
                         "messages": build_messages(lang, title,
                                                    prepare_source(title))},
            }, ensure_ascii=False))
        payload = ("\n".join(lines) + "\n").encode()
        name = f"{args.tier}-{stamp}-{n}"
        up = upload_file(key, name + ".jsonl", payload)
        batch = openai_json(key, "/batches", {
            "input_file_id": up["id"],
            "endpoint": "/v1/chat/completions",
            "completion_window": "24h",
            "metadata": {"tier": args.tier, "chunk": str(n)},
        })
        with open(batch_state_path(name), "w", encoding="utf-8") as f:
            json.dump({"batch_id": batch["id"], "tier": args.tier,
                       "model": args.model, "index": index}, f,
                      ensure_ascii=False, indent=1)
        print(f"{name}: submitted {len(chunk)} articles as {batch['id']} "
              f"({len(payload) / 1e6:.1f} MB)")
    return 0


def open_batches():
    if not os.path.isdir(BATCH_DIR):
        return []
    out = []
    for fn in sorted(os.listdir(BATCH_DIR)):
        if fn.endswith(".json"):
            with open(os.path.join(BATCH_DIR, fn), encoding="utf-8") as f:
                out.append((fn[:-5], json.load(f)))
    return out


def cmd_batch_poll(args):
    key = openai_key()
    while True:
        alive = 0
        for name, state in open_batches():
            if state.get("collected"):
                continue
            b = openai_json(key, f"/batches/{state['batch_id']}")
            c = b.get("request_counts") or {}
            print(f"{name}: {b['status']} "
                  f"{c.get('completed', 0)}/{c.get('total', 0)} done, "
                  f"{c.get('failed', 0)} failed")
            if b["status"] in ("validating", "in_progress", "finalizing"):
                alive += 1
        if not args.watch or not alive:
            return 0
        time.sleep(args.interval)


def cmd_batch_collect(args):
    key = openai_key()
    kept = failed = 0
    for name, state in open_batches():
        if state.get("collected") and not args.force:
            continue
        b = openai_json(key, f"/batches/{state['batch_id']}")
        if b["status"] != "completed":
            print(f"{name}: {b['status']}, skipping")
            continue
        out = openai_request(key, f"/files/{b['output_file_id']}/content").decode()
        for line in out.splitlines():
            if not line.strip():
                continue
            r = json.loads(line)
            lang, title = state["index"][r["custom_id"]]
            body = (r.get("response") or {}).get("body") or {}
            choices = body.get("choices") or []
            reply = choices[0]["message"]["content"] if choices else ""
            page_title, text = split_title(reply)
            problems = validate(prepare_source(title), text, lang)
            if problems or not page_title:
                failed += 1
                print(f"  !! {lang}/{title}: "
                      f"{'; '.join(problems or ['no TITLE line'])}", file=sys.stderr)
                continue
            save(lang, title, state["model"], page_title, text)
            kept += 1
        state["collected"] = True
        with open(batch_state_path(name), "w", encoding="utf-8") as f:
            json.dump(state, f, ensure_ascii=False, indent=1)
        print(f"{name}: collected")
    print(f"{kept} translations saved, {failed} rejected "
          f"(re-run with `translate` to redo those live)")
    return 0


# --------------------------------------------------------------------------
# support templates
#
# A pushed article is full of {{Coords}}, {{IsIn}}, {{stub}} and motorway
# shields. Outside en (and de) those templates do not exist, and a missing
# template renders as its own literal source in the middle of the article. So
# whatever the articles for a wiki actually call gets copied over from English
# first, following the templates those templates call in turn.
# --------------------------------------------------------------------------

MAGIC_WORDS = re.compile(
    r"^(?:#|DEFAULTSORT|DISPLAYTITLE|FULLPAGENAME|PAGENAME|NAMESPACE|SITENAME|"
    r"CURRENT|LOCAL|SUBST|MSG|INT:|NS:|FORMATNUM|PLURAL|GENDER|UC|LC|PADLEFT|"
    r"PADRIGHT|URLENCODE|ANCHORENCODE|FULLURL|LOCALURL|CANONICALURL|filepath:|"
    r"REVISION|PROTECTIONLEVEL|NUMBEROF|SERVER|SCRIPTPATH|STYLEPATH|CONTENT)",
    re.I)


def template_calls(text):
    names = set()
    for raw in TEMPLATE_RE.findall(text):
        name = raw.strip()
        if MAGIC_WORDS.match(name) or "{" in name:
            continue
        # Already served from en over the interwiki; nothing to copy locally.
        if name.lower().startswith("hwen:"):
            continue
        name = re.sub(r"^:?[Tt]emplate:", "", name).replace("_", " ").strip()
        if not name:
            continue
        names.add(name[:1].upper() + name[1:])
    return names


def needed_templates(titles):
    """Every template the given English articles use, transitively."""
    seen, queue = set(), set()
    for t in titles:
        queue |= template_calls(prepare_source(t))
    while queue:
        name = queue.pop()
        if name in seen:
            continue
        seen.add(name)
        r = api("en", {"action": "query", "titles": f"Template:{name}",
                       "prop": "revisions", "rvprop": "content", "rvslots": "main"})
        for p in r["query"].get("pages", []):
            if p.get("missing"):
                continue
            body = p["revisions"][0]["slots"]["main"].get("content", "")
            TEMPLATE_TEXT[name] = body
            queue |= (template_calls(body) - seen)
    return sorted(n for n in seen if n in TEMPLATE_TEXT)


TEMPLATE_TEXT = {}

# Templates that are mostly a sentence addressed to the reader. Copying these
# verbatim would leave English prose on a translated page, so the model
# translates the prose inside them — once per language, not once per article.
# `Ai-enhanced` is deliberately absent: it is transcluded from en, see AI_BANNER.
PROSE_TEMPLATES = {"Record-ride", "Stub", "Nomadwiki", "Coords",
                   "Hitchbase city", "Hitchbase_city"}

TEMPLATE_SYSTEM = """\
You translate MediaWiki template source for Hitchwiki from English into {language}.

Return ONLY the template source. No preamble, no code fences, no commentary.

This is code that renders a box or a label on an article. Translate the words a \
reader sees and NOTHING else. Everything else must come back byte-identical: HTML \
tags and their style/class attributes, `<includeonly>`, `<noinclude>`, parser \
functions such as `{{{{#ifeq:}}}}`, parameter references such as `{{{{{{1}}}}}}`, \
magic words, table markup, URLs, file names, `[[Category:...]]` names and wikilink \
targets. Keep the exact same number of braces everywhere."""


def translate_template(key, model, lang, name, source):
    messages = [
        {"role": "system", "content": TEMPLATE_SYSTEM.format(
            language=LANGUAGE_NAMES[lang])},
        {"role": "user", "content": f"Template:{name}\n\n---\n{source}"},
    ]
    text = call_chat(key, model, messages).strip()
    if text.startswith("```"):
        text = re.sub(r"^```[a-z]*\n", "", text)
        text = re.sub(r"\n```\s*$", "", text)
    # A template whose braces or parameter references came back different is
    # broken code, not a bad translation — keep the English one instead.
    for token in ("{{{", "}}}", "<includeonly>", "<noinclude>"):
        if source.count(token) != text.count(token):
            return source
    if abs(source.count("{{") - text.count("{{")) > 0:
        return source
    return text


def cmd_bootstrap(args):
    idx = load_index()
    langs = None if args.lang == "all" else args.lang.split(",")
    jobs = tier_targets(idx, args.tier, langs, args.top)
    by_lang = {}
    for lang, title in jobs:
        by_lang.setdefault(lang, []).append(title)
    if not by_lang:
        print("nothing to bootstrap")
        return 0

    key = openai_key() if not args.verbatim else None
    # Read every template English has for this tier once, before the workers
    # start: TEMPLATE_TEXT is shared and filling it concurrently would fetch
    # each one 26 times over.
    all_names = needed_templates([t for _, t in jobs])
    print(f"{len(all_names)} templates used by this tier on en", file=sys.stderr)

    def run(lang):
        titles = by_lang[lang]
        names = needed_templates(titles)
        present = page_lengths(lang, [f"Template:{n}" for n in names])
        missing = [n for n in names if f"Template:{n}" not in present]
        if not missing:
            with _lock:
                print(f"{lang}: all {len(names)} templates present", flush=True)
            return
        with _lock:
            print(f"{lang}: {len(missing)} of {len(names)} templates missing",
                  flush=True)
        if args.dry_run:
            return
        wrote = 0
        for name in missing:
            source = TEMPLATE_TEXT[name]
            if key and name in PROSE_TEMPLATES:
                body = translate_template(key, args.model, lang, name, source)
            else:
                body = source
            try:
                put_wikitext(lang, f"Template:{name}", body,
                             f"Copied from [[:en:Template:{name}]] for translated articles")
            except RuntimeError as e:
                # One unwritable template must not cost the language the other 46.
                with _lock:
                    print(f"  !! {e}", file=sys.stderr)
                continue
            wrote += 1
        with _lock:
            print(f"{lang}: wrote {wrote} templates", flush=True)

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        for fut in concurrent.futures.as_completed(
                {pool.submit(run, lang): lang for lang in sorted(by_lang)}):
            fut.result()
    return 0


# --------------------------------------------------------------------------
# pushing
# --------------------------------------------------------------------------

def put_wikitext(lang, title, text, summary, create_only=False, retries=4):
    """Write a page as a bot edit.

    Retried: a run makes thousands of these, and under that load an
    `edit.php` occasionally dies part-way through its save with no error on
    stderr at all. Re-running it is safe — the same text saved twice is a null
    edit.
    """
    cmd = ["docker", "exec", "-i", CONTAINER, "php",
           "/var/www/html/maintenance/run.php", "edit", f"--wiki={lang}",
           "--bot", "--summary", summary]
    if create_only:
        cmd.append("--createonly")
    cmd.append(title)
    last = None
    for attempt in range(retries):
        r = subprocess.run(cmd, input=text, capture_output=True, text=True)
        if r.returncode == 0:
            return r.stdout.strip()
        last = r.stderr.strip() or r.stdout.strip()
        time.sleep(3 * (attempt + 1))
    raise RuntimeError(f"{lang}:{title}: {last}")


# The banner saying a language model wrote this and a local should check it.
# Transcluded from the English wiki rather than copied to each wiki: there is
# one `Template:Ai-enhanced`, on `en`, and rewording it reaches all 34 at once.
# `hwen:` is the same interwiki route `Template:Events` already mirrors over,
# and it fetches raw wikitext, so the `{{FULLPAGENAME}}` in the banner's "correct
# this article" link still resolves to the local page.
AI_BANNER = "{{hwen:Ai-enhanced}}"


def article_body(page):
    """The wikitext as it goes on the wiki: the translation, plus the banner
    that says a language model wrote it and asks a local to check it."""
    return page["text"].rstrip() + "\n\n" + AI_BANNER + "\n"


def push_lang(lang, jobs, args):
    pages = {}
    for title in jobs:
        path = out_path(lang, title)
        if os.path.exists(path):
            with open(path, encoding="utf-8") as f:
                pages[title] = json.load(f)
    if not pages:
        return
    # One existence check for the whole language rather than one per article:
    # at this volume a per-article round trip costs longer than the edits do.
    probe = sorted({p["title"] for p in pages.values()} | set(pages))
    live = page_lengths(lang, probe)

    wrote = skipped = 0
    for title, page in pages.items():
        target = page["title"]
        if target in live and not args.force:
            skipped += 1
            continue
        if args.dry_run:
            wrote += 1
            continue
        try:
            put_wikitext(lang, target, article_body(page),
                         SUMMARY.format(model=page["model"], source=title))
        except RuntimeError as e:
            with _lock:
                print(f"  !! {e}", file=sys.stderr)
            continue
        live[target] = len(page["text"])
        wrote += 1
        # A redirect from the English title, so links written on any wiki
        # against the English key keep resolving on this one too.
        if target != title and title not in live:
            try:
                put_wikitext(lang, title, f"#REDIRECT [[{target}]]\n",
                             f"Redirect to [[{target}]]")
                live[title] = 0
            except RuntimeError as e:
                with _lock:
                    print(f"  !! {e}", file=sys.stderr)
        if wrote % 25 == 0:
            with _lock:
                print(f"{lang}: {wrote} written …", flush=True)
    with _lock:
        print(f"{lang}: wrote {wrote}, left {skipped} existing pages alone",
              flush=True)


def cmd_push(args):
    idx = load_index()
    langs = None if args.lang == "all" else args.lang.split(",")
    jobs = tier_targets(idx, args.tier, langs, args.top)
    by_lang = {}
    for lang, title in jobs:
        by_lang.setdefault(lang, []).append(title)
    # Each edit.php invocation boots MediaWiki from scratch. The wikis have
    # separate databases, so one worker per language is safe and turns hours
    # into minutes.
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        futures = {pool.submit(push_lang, l, t, args): l for l, t in by_lang.items()}
        for fut in concurrent.futures.as_completed(futures):
            lang = futures[fut]
            try:
                fut.result()
            except Exception as e:
                print(f"{lang}: FAILED {e}", file=sys.stderr)
    return 0


# --------------------------------------------------------------------------
# interlanguage / SharedInfobox registration
# --------------------------------------------------------------------------

def cmd_register(args):
    """Point page_translations at what the wikis actually hold now.

    This is what makes the interlanguage sidebar work and what SharedInfobox
    matches on to render the English infobox on the translation, so it has to
    run after every push.
    """
    idx = load_index()
    langs = None if args.lang == "all" else args.lang.split(",")
    jobs = tier_targets(idx, args.tier, langs, args.top)
    concepts = {}
    for lang, title in jobs:
        path = out_path(lang, title)
        if os.path.exists(path):
            with open(path, encoding="utf-8") as f:
                concepts.setdefault(title, {})[lang] = json.load(f)["title"]

    # Which of those pages really made it onto each wiki — asked once per
    # language over all its titles, not once per concept per language.
    wanted = {}
    for source, by_lang in concepts.items():
        for lang, title in by_lang.items():
            wanted.setdefault(lang, set()).add(title)
    live_titles = {}
    for lang, titles in sorted(wanted.items()):
        live_titles[lang] = set(page_lengths(lang, sorted(titles)))
        print(f"{lang}: {len(live_titles[lang])} of {len(titles)} pages live",
              file=sys.stderr)

    def register_one(item):
        source, by_lang = item
        live = {l: t for l, t in by_lang.items() if t in live_titles.get(l, ())}
        if not live:
            return f"{source}: nothing live, skipped"
        if args.dry_run:
            return f"{source}: would register {len(live)}: {sorted(live)}"
        cmd = ["docker", "exec", CONTAINER, "php",
               "/var/www/html/maintenance/run.php",
               "/var/www/html/extensions/CentralLangLinks/maintenance/setTranslations.php",
               "--wiki=en", "--concept", source]
        for lang, title in sorted(live.items()):
            cmd += ["--link", f"{lang}:{title}"]
        r = subprocess.run(cmd, capture_output=True, text=True)
        if r.returncode != 0:
            return f"{source}: FAILED {r.stderr.strip()[:200]}"
        return f"{source}: registered {len(live)} languages"

    # Each call boots MediaWiki; the concepts are independent rows in one
    # shared table, so they can go several at a time.
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        for line in pool.map(register_one, sorted(concepts.items())):
            print(line, flush=True)
    return 0


# --------------------------------------------------------------------------
# planning
# --------------------------------------------------------------------------

def cmd_plan(args):
    idx = load_index()
    langs = None if args.lang == "all" else args.lang.split(",")
    tiers = TIERS if args.tier == "all" else [args.tier]
    grand = 0
    for tier in tiers:
        jobs = tier_jobs(idx, tier, langs, args.top)
        todo = pending(idx, tier, langs, top=args.top)
        by_lang = {}
        for lang, title in jobs:
            by_lang.setdefault(lang, []).append(title)
        chars = sum(len(prepare_source(t)) for _, t in todo) if args.bytes else 0
        print(f"\n== {tier}: {len(jobs)} missing on the wikis, "
              f"{len(todo)} not yet translated"
              + (f", {chars / 1e6:.1f} MB of source" if args.bytes else ""))
        for lang in sorted(by_lang):
            print(f"   {lang}: {len(by_lang[lang])}")
        grand += len(jobs)
    print(f"\n{grand} articles in total")
    return 0


def main():
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)

    def common(sp, tier_default="countries", choices=TIERS):
        sp.add_argument("--tier", default=tier_default, choices=choices)
        sp.add_argument("--lang", default="all")
        sp.add_argument("--top", type=int,
                        help="major-cities: how many by inbound links "
                             f"(default {MAJOR_BY_INBOUND}); capitals are always added")
        return sp

    pl = common(sub.add_parser("plan"), "all", ("all",) + TIERS)
    pl.add_argument("--bytes", action="store_true", help="also size the source")
    pl.set_defaults(func=cmd_plan)

    t = common(sub.add_parser("translate"))
    t.add_argument("--model", default=DEFAULT_MODEL)
    t.add_argument("-j", "--jobs", type=int, default=8)
    t.add_argument("--force", action="store_true")
    t.add_argument("--limit", type=int, help="only the first N (for trying it out)")
    t.set_defaults(func=cmd_translate)

    b = sub.add_parser("batch")
    bsub = b.add_subparsers(dest="bcmd", required=True)
    bs = common(bsub.add_parser("submit"))
    bs.add_argument("--model", default=DEFAULT_MODEL)
    bs.add_argument("--chunk", type=int, default=2000)
    bs.add_argument("--force", action="store_true")
    bs.add_argument("--limit", type=int, help="only the first N (for trying it out)")
    bs.set_defaults(func=cmd_batch_submit)
    bp = bsub.add_parser("poll")
    bp.add_argument("--watch", action="store_true")
    bp.add_argument("--interval", type=int, default=120)
    bp.set_defaults(func=cmd_batch_poll)
    bc = bsub.add_parser("collect")
    bc.add_argument("--force", action="store_true")
    bc.set_defaults(func=cmd_batch_collect)

    bo = common(sub.add_parser("bootstrap"))
    bo.add_argument("--model", default=DEFAULT_MODEL)
    bo.add_argument("--verbatim", action="store_true",
                    help="copy templates without translating their prose")
    bo.add_argument("-j", "--jobs", type=int, default=6, help="languages in parallel")
    bo.add_argument("--dry-run", action="store_true")
    bo.set_defaults(func=cmd_bootstrap)

    u = common(sub.add_parser("push"))
    u.add_argument("-j", "--jobs", type=int, default=8)
    u.add_argument("--dry-run", action="store_true")
    u.add_argument("--force", action="store_true")
    u.set_defaults(func=cmd_push)

    r = common(sub.add_parser("register"))
    r.add_argument("-j", "--jobs", type=int, default=6)
    r.add_argument("--dry-run", action="store_true")
    r.set_defaults(func=cmd_register)

    args = p.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
