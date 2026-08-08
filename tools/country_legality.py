#!/usr/bin/env python3
"""Put a sourced statement of the law at the top of each country's
`Legality of Hitchhiking` section on the English wiki.

Most country articles either had no such section at all or said "it's illegal
on motorways" with nothing to check that against. This tool ships, per country,
a short block naming the actual provision — `§ 18(9) StVO`, `art. 175 comma 7
lett. b CdS`, `art. 125 RGC` — and linking it on the government's own legal
database, so a reader can verify it and so it stays checkable when the law
moves. Each block answers the same four questions and nothing else:

  * is hitchhiking as such regulated, or only where you stand?
  * motorways and expressways: carriageway, shoulder, on-ramp
  * service areas, rest areas and toll plazas
  * ordinary roads

The block is at most five sentences. Everything a wiki author already wrote in
that section is kept, underneath it — this adds the law, it does not replace
anyone's practical advice.

The exception is a section that was already nothing but an unsourced version of
the same four answers, which would then stand next to the sourced one saying it
again. A block file may open with `%replace` to say that its section was read
and is superseded, and carry the paragraphs still worth having after a `%tail`
line. Both are a decision recorded in the repo rather than in an edit summary,
and the old wording stays in the page history either way.

    python3 tools/country_legality.py plan             # what's on the wiki now
    python3 tools/country_legality.py render Germany   # the block on its own
    python3 tools/country_legality.py diff Germany     # against the live page
    python3 tools/country_legality.py push Germany     # write it
    python3 tools/country_legality.py push all

Re-running is safe. The block is fenced in HTML comments, so `push` replaces
the previous one rather than stacking a second copy, and an edit that would
change nothing is skipped.

Only the English wiki is touched. The other 33 wikis' country articles are
translated from English by `translate_place_articles.py`, and re-translating
them against a changed section is a separate job.
"""

import argparse
import difflib
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse

CONTAINER = "hitchwiki-mediawiki"
HERE = os.path.dirname(os.path.abspath(__file__))
BLOCKS = os.path.join(HERE, "country_legality")

SUMMARY = ("Legality section: what the country's own traffic law says, "
           "with a link to the official text")

OPEN = "<!-- LEGALITY-LAW: from tools/country_legality/, see tools/country_legality.py -->"
CLOSE = "<!-- /LEGALITY-LAW -->"

# The heading the block goes under. `restructure_country_articles.py` has
# already normalised the English country articles onto this exact wording, so a
# match is an exact match; the looser pattern is only for the few that section
# was never run against.
LEGALITY_RE = re.compile(r"^(=+)\s*Legality(?:\s+of\s+Hitchhiking)?\s*\1\s*$",
                         re.M | re.I)
HITCHHIKING_RE = re.compile(r"^(=+)\s*Hitchhiking\s*\1\s*$", re.M | re.I)
HEADING_RE = re.compile(r"^(=+)\s*[^=\n].*?\s*\1\s*$", re.M)


# --------------------------------------------------------------------------
# wiki access
# --------------------------------------------------------------------------

def api(lang, params, retries=5):
    params = dict(params, format="json", formatversion=2)
    url = f"http://localhost/{lang}/api.php?" + urllib.parse.urlencode(params)
    last = None
    for attempt in range(retries):
        r = subprocess.run(
            ["docker", "exec", CONTAINER, "curl", "-s", "--max-time", "120", url],
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


def load_raw(titles):
    """Current wikitext of each article, straight from the wiki."""
    out = {}
    for i in range(0, len(titles), 25):
        r = api("en", {"action": "query", "prop": "revisions",
                       "rvprop": "content", "rvslots": "main",
                       "titles": "|".join(titles[i:i + 25])})
        for p in r["query"]["pages"]:
            if "revisions" in p:
                out[p["title"]] = p["revisions"][0]["slots"]["main"]["content"]
    return out


def put_wikitext(lang, title, text, summary, retries=4):
    """Write a page as a bot edit."""
    cmd = ["docker", "exec", "-i", CONTAINER, "php",
           "/var/www/html/maintenance/run.php", "edit", f"--wiki={lang}",
           "--bot", "--summary", summary, title]
    last = None
    for attempt in range(retries):
        r = subprocess.run(cmd, input=text, capture_output=True, text=True)
        if r.returncode == 0:
            return r.stdout.strip()
        last = r.stderr.strip() or r.stdout.strip()
        time.sleep(3 * (attempt + 1))
    raise RuntimeError(f"{lang}:{title}: {last}")


# --------------------------------------------------------------------------
# splicing
# --------------------------------------------------------------------------

def top_depth(text):
    """The article's own top heading level.

    A few of these articles are written entirely in `=` and a few in `===`; a
    new section has to match, or it nests itself under the one above it.
    """
    depths = [len(m.group(1)) for m in HEADING_RE.finditer(text)]
    return min(depths) if depths else 2


def section_bounds(text, match):
    """Where the section opened by `match` ends: the next heading at the same
    level or shallower, or the end of the article."""
    depth = len(match.group(1))
    rest = text[match.end():]
    nxt = re.search(r"^={1,%d}\s*[^=\n].*?=+\s*$" % depth, rest, re.M)
    return match.end(), match.end() + (nxt.start() if nxt else len(rest))


def strip_block(body):
    """The section body with any previously pushed block removed."""
    return re.sub(re.escape(OPEN) + r".*?" + re.escape(CLOSE) + r"\n*",
                  "", body, flags=re.S)


def fenced(block):
    return f"{OPEN}\n{block.strip()}\n{CLOSE}\n"


def build(text, spec):
    """`text` with `spec`'s block at the top of its Legality section.

    Three cases, in the order they are tried: the section exists (put the block
    at its top, keep the rest); there is a Hitchhiking section but no Legality
    one (add Legality straight after it, which is the order
    `restructure_country_articles.py` established); neither (append).
    """
    block, replace, tail = spec["block"], spec["replace"], spec["tail"]
    m = LEGALITY_RE.search(text)
    if m:
        start, end = section_bounds(text, m)
        kept = tail if replace else strip_block(text[start:end]).strip()
        body = "\n" + fenced(block) + (("\n" + kept.strip() + "\n") if kept.strip() else "")
        return text[:start] + body + "\n" + text[end:].lstrip("\n")

    eq = "=" * top_depth(text)
    body = fenced(block) + (("\n" + tail.strip() + "\n") if tail.strip() else "")
    section = f"{eq} Legality of Hitchhiking {eq}\n\n{body}"

    h = HITCHHIKING_RE.search(text)
    if h:
        _, end = section_bounds(text, h)
        return text[:end].rstrip() + "\n\n" + section + "\n" + text[end:].lstrip("\n")
    return text.rstrip() + "\n\n" + section


def sentences(block):
    """Sentence count, for the five-sentence cap.

    These blocks are dense with abbreviations that carry a full stop mid
    sentence — `art. 175`, `Act No. 361/2000 Sb.]`, `§ 46(4)(e)`, `silnice I.
    třídy` — and listing them all was a losing game. What actually separates a
    sentence here is the punctuation being followed by whitespace and then
    something a sentence can start with: a capital, a `§`, or the opening of a
    link or of italics. An abbreviation's full stop is followed by a digit, a
    lowercase word or a closing bracket instead, so it does not count.
    """
    t = re.sub(r"\[https?://\S+", "[", block)
    return len(re.findall(r"[.!?]\s+(?=[A-Z§\[]|'')", t)) + 1


# --------------------------------------------------------------------------
# commands
# --------------------------------------------------------------------------

def block_path(country):
    return os.path.join(BLOCKS, country.replace(" ", "_") + ".wikitext")


def have_blocks():
    if not os.path.isdir(BLOCKS):
        return []
    return sorted(f[:-len(".wikitext")].replace("_", " ")
                  for f in os.listdir(BLOCKS) if f.endswith(".wikitext"))


def read_block(country):
    """Parse a block file into {block, replace, tail}.

    `%replace` on the first line means the live section was read and is
    superseded; anything after a `%tail` line is what of it is worth carrying
    over. Without `%replace` the live section is kept as it stands and `%tail`
    would be ambiguous, so it is rejected.
    """
    path = block_path(country)
    if not os.path.exists(path):
        raise SystemExit(f"no block written for {country} ({path})")
    with open(path, encoding="utf-8") as f:
        text = f.read().strip()

    replace = False
    if text.startswith("%replace"):
        replace = True
        text = text.split("\n", 1)[1] if "\n" in text else ""

    block, tail = text, ""
    m = re.search(r"^%tail\s*$", text, re.M)
    if m:
        if not replace:
            raise SystemExit(f"{country}: %tail without %replace")
        block, tail = text[:m.start()], text[m.end():]

    return {"block": block.strip(), "replace": replace, "tail": tail.strip()}


def targets(args):
    if not args.countries or args.countries == ["all"]:
        return have_blocks()
    return list(args.countries)


def cmd_plan(args):
    names = targets(args)
    raw = load_raw(names)
    print(f"{'country':<22} {'live':>6}  {'block':>5}  section now")
    for country in names:
        text = raw.get(country)
        if text is None:
            print(f"{country:<22} {'-':>6}  {'?':>5}  NO SUCH ARTICLE")
            continue
        m = LEGALITY_RE.search(text)
        if m:
            start, end = section_bounds(text, m)
            body = strip_block(text[start:end]).strip()
            state = f"{len(body.split())} words kept"
            if OPEN in text[start:end]:
                state += ", block already pushed"
        else:
            state = "none — will be created"
        n = sentences(read_block(country)["block"]) if os.path.exists(block_path(country)) else 0
        print(f"{country:<22} {len(text.split()):>6}  {n:>5}  {state}")


def cmd_render(args):
    for country in targets(args):
        spec = read_block(country)
        print(f"===== {country}"
              + ("   [%replace]" if spec["replace"] else ""))
        print(spec["block"])
        if spec["tail"]:
            print("\n--- kept from the old section:\n" + spec["tail"])
        print()


def cmd_diff(args):
    raw = load_raw(targets(args))
    for country in targets(args):
        old = raw.get(country)
        if old is None:
            print(f"!! {country}: no such article")
            continue
        new = build(old, read_block(country))
        d = list(difflib.unified_diff(old.splitlines(), new.splitlines(),
                                      f"{country} (live)", f"{country} (new)",
                                      lineterm="", n=2))
        print("\n".join(d) if d else f"== {country}: no change")
        print()


def cmd_check(args):
    """Refuse anything that is not what this tool is for: over five sentences,
    or a block with no link to a legal source in it."""
    bad = 0
    for country in targets(args):
        block = read_block(country)["block"]
        problems = []
        n = sentences(block)
        if n > 5:
            problems.append(f"{n} sentences (max 5)")
        if not re.search(r"\[https?://", block):
            problems.append("no source link")
        for url in re.findall(r"\[(https?://\S+)", block):
            if url.rstrip("/").endswith(("wikipedia.org", "hitchwiki.org")):
                problems.append(f"not a primary source: {url}")
        if problems:
            bad += 1
            print(f"!! {country}: {'; '.join(problems)}")
    print(f"{len(targets(args))} checked, {bad} rejected")
    return 1 if bad else 0


def cmd_push(args):
    names = targets(args)
    if cmd_check(args):
        raise SystemExit("nothing pushed")
    raw = load_raw(names)
    wrote = skipped = 0
    for country in names:
        old = raw.get(country)
        if old is None:
            print(f"!! {country}: no such article — skipped")
            continue
        new = build(old, read_block(country))
        if new == old:
            skipped += 1
            continue
        if args.dry_run:
            print(f"would edit {country}")
            wrote += 1
            continue
        put_wikitext("en", country, new, SUMMARY)
        wrote += 1
        print(f"edited {country}")
        time.sleep(0.2)
    print(f"\n{wrote} edited, {skipped} unchanged")


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)

    def common(p):
        p.add_argument("countries", nargs="*",
                       help="country articles, or 'all' / nothing for every "
                            "block in tools/country_legality/")
        return p

    common(sub.add_parser("plan")).set_defaults(fn=cmd_plan)
    common(sub.add_parser("render")).set_defaults(fn=cmd_render)
    common(sub.add_parser("diff")).set_defaults(fn=cmd_diff)
    common(sub.add_parser("check")).set_defaults(fn=cmd_check)
    p = common(sub.add_parser("push"))
    p.add_argument("--dry-run", action="store_true")
    p.set_defaults(fn=cmd_push)

    args = ap.parse_args()
    sys.exit(args.fn(args) or 0)


if __name__ == "__main__":
    main()
