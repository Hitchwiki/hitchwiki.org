#!/usr/bin/env python3
"""Give every English country article the same three-part opening.

A country article should open with a couple of sentences saying what and where
the country is, then `== Hitchhiking ==`, then `== Legality of Hitchhiking ==`.
Most of them instead opened with one undifferentiated wall of lead text that
mixed all three — borders and currency, what drivers are like, and whether the
police will fine you — and a reader looking for one of those had to read all of
it.

Nothing here rewrites prose. The lead is cut into sentence-sized spans, a model
says which of the three buckets each span belongs in, and the article is
reassembled **from the original byte spans**: every span is used exactly once,
in its original relative order, so the only thing that can change is which
heading a sentence sits under. `check` re-derives that invariant from the cache
and fails loudly if a run ever broke it.

    tools/restructure_country_articles.py plan                 # what is where now
    tools/restructure_country_articles.py classify --all -j 8  # ask the model
    tools/restructure_country_articles.py render Germany       # see the diff
    tools/restructure_country_articles.py check                # verbatim? balanced?
    tools/restructure_country_articles.py push --all           # write to the wiki
"""

import argparse
import concurrent.futures as futures
import difflib
import json
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from place_corpus import CONTAINER, ROOT, api, load_index, slug  # noqa: E402
from translate_place_articles import (  # noqa: E402
    DEFAULT_MODEL, call_chat, openai_key, put_wikitext,
)

CACHE = os.path.join(ROOT, "tools", "country_restructure")

HITCH_HEADING = "Hitchhiking"
LEGAL_HEADING = "Legality of Hitchhiking"

SUMMARY = ("Restructure: short intro, then == Hitchhiking ==, then "
           "== Legality of Hitchhiking == (text moved, not changed)")


# --------------------------------------------------------------------------
# parsing an article
# --------------------------------------------------------------------------

MAGIC = re.compile(r"__[A-Z]+__")
COMMENT = re.compile(r"<!--.*?-->", re.S)


def _template_end(text, i):
    """Index just past the `{{...}}` starting at `i`, or None."""
    if not text.startswith("{{", i):
        return None
    depth, j = 0, i
    while j < len(text):
        if text.startswith("{{", j):
            depth += 1
            j += 2
        elif text.startswith("}}", j):
            depth -= 1
            j += 2
            if depth == 0:
                return j
        else:
            j += 1
    return None


def split_prelude(text):
    """The templates and magic words an article opens with, before any prose.

    `{{Record-ride}}` and `{{Infobox Country}}` have to stay at the very top:
    the infobox floats right and the reader's first sentence has to sit beside
    it, not under it.
    """
    i = 0
    while i < len(text):
        m = re.match(r"\s+", text[i:])
        if m:
            i += m.end()
            continue
        for pat in (MAGIC, COMMENT):
            m = pat.match(text, i)
            if m:
                i = m.end()
                break
        else:
            end = _template_end(text, i)
            if end is None:
                break
            i = end
    return text[:i], text[i:]


SECTION_RE = re.compile(r"(?m)^[ \t]*(=+)[ \t]*([^=\n][^\n]*?)[ \t]*=+[ \t]*$")


def split_sections(body):
    """`(lead, orphans, sections, level)` — the article's top-level blocks.

    "Top-level" is whatever depth the article itself uses, not `==`: a few of
    these articles are written entirely in `=` and a few entirely in `===`, and
    the new headings have to come out at the same depth or they invert the
    article's structure. Anything deeper nests inside its parent's body and
    travels with it.

    `orphans` is the awkward case: headings that sit above the article's first
    top-level one. They are left verbatim, immediately after the lead, because
    that is where they already are.
    """
    marks = list(SECTION_RE.finditer(body))
    if not marks:
        return body, "", [], 2
    lead = body[:marks[0].start()]
    level = min(len(m.group(1)) for m in marks)
    tops = [k for k, m in enumerate(marks) if len(m.group(1)) == level]

    orphan_end = marks[tops[0]].start() if tops else len(body)
    orphans = body[marks[0].start():orphan_end]

    sections = []
    for n, k in enumerate(tops):
        m = marks[k]
        end = marks[tops[n + 1]].start() if n + 1 < len(tops) else len(body)
        sections.append({"title": m.group(2).strip(), "body": body[m.end():end]})
    return lead, orphans, sections, level


# Sentence enders that are not sentence ends. "e.g." and friends, plus the
# units and titles that show up in this wiki's prose.
ABBREV = {
    "e.g", "i.e", "etc", "vs", "approx", "ca", "cca", "resp", "incl", "no",
    "nr", "st", "mt", "mr", "mrs", "ms", "dr", "prof", "min", "max", "km",
    "a.k.a", "u.s", "u.k", "e.v", "fig", "op", "cit", "al", "jr", "sr", "ft",
}

BLOCK_START = re.compile(r"[*#:;|!]|\{\|")


def _sentence_spans(text, base):
    """Spans of `text`, split at sentence ends outside links and templates."""
    spans, start, depth_sq, depth_br, depth_par = [], 0, 0, 0, 0
    i = 0
    while i < len(text):
        c = text[i]
        if text.startswith("[[", i):
            depth_sq += 1
            i += 2
            continue
        if text.startswith("]]", i):
            depth_sq = max(0, depth_sq - 1)
            i += 2
            continue
        if text.startswith("{{", i):
            depth_br += 1
            i += 2
            continue
        if text.startswith("}}", i):
            depth_br = max(0, depth_br - 1)
            i += 2
            continue
        if c == "(":
            depth_par += 1
        elif c == ")":
            depth_par = max(0, depth_par - 1)
        elif c in ".!?" and not (depth_sq or depth_br or depth_par):
            j = i + 1
            # Trailing quotes, italics markup and closing brackets belong to
            # the sentence that ends here, not to the next one.
            while j < len(text) and text[j] in "'\")]»”’":
                j += 1
            k = j
            while k < len(text) and text[k] in " \t":
                k += 1
            newline = k < len(text) and text[k] == "\n"
            while k < len(text) and text[k] in " \t\n":
                k += 1
            if k > j and k < len(text) and _starts_sentence(text, k):
                word = re.search(r"[\w.]+$", text[:i + 1])
                token = word.group(0).rstrip(".").lower() if word else ""
                if token not in ABBREV and not (len(token) == 1 and not newline):
                    spans.append((base + start, base + j))
                    start = k
                    i = k
                    continue
        i += 1
    if start < len(text):
        spans.append((base + start, base + len(text)))
    return spans


def _starts_sentence(text, k):
    c = text[k]
    if c.isupper() or c.isdigit():
        return True
    # '''Bold''', [[Link]], {{Template}}, "quote", (aside), <ref>
    return text.startswith(("'''", "[[", "{{", "<"), k) or c in '"“(['


PARA_RE = re.compile(r"(?:[^\n]*\S[^\n]*(?:\n|$))+")


def lead_spans(lead):
    """The lead cut into assignable spans, each tagged with its paragraph.

    Lists, tables and image-only lines stay whole: they have no sentences to
    split and nothing is gained by letting a bucket boundary land inside one.
    """
    spans = []
    for pidx, m in enumerate(PARA_RE.finditer(lead)):
        chunk = m.group(0).rstrip()
        start = m.start()
        if BLOCK_START.match(chunk) or re.fullmatch(
                r"\[\[\s*(?:File|Image)\s*:.*\]\]", chunk, re.I | re.S):
            spans.append((start, start + len(chunk), pidx))
        else:
            for a, b in _sentence_spans(chunk, start):
                spans.append((a, b, pidx))
    return spans


def parse(text):
    prelude, rest = split_prelude(text)
    lead, orphans, sections, level = split_sections(rest)
    return {"prelude": prelude, "lead": lead, "orphans": orphans,
            "sections": sections, "level": level, "spans": lead_spans(lead)}


# --------------------------------------------------------------------------
# reassembly
# --------------------------------------------------------------------------

# A section is folded into one of the two new headings only when its title is
# already a synonym for that heading. The model is a good judge of what a
# section is *about*, and a bad one of whether renaming it loses something:
# left to itself it offered to retitle "People", "Road network" and "Border
# crossing". Absorbing "Hitchhiking" is a rename in name only and has to happen
# — two sections cannot both be called that. Absorbing "Road network" would
# throw away a heading a reader was using. So the model picks, and this decides
# whether the pick is a rename or a loss; anything not matched stays put, in
# its original place, and the article simply gains a new section above it.
ABSORBABLE = {
    "hitch": re.compile(r"""(?x)
        (hitch-?\ ?hiking|hitching|hitch)
            (\ (culture|in\ general|around|here|scene|situation))?$
        | (in\ )?general(\ (information|info|tips|advice))?$
        | how\ to\ hitch(-?hike)?$
        | hitchability$
    """, re.I),
    "legal": re.compile(r"""(?x)
        (the\ )?legality(\ of\ hitch-?hiking)?$
        | legal(\ (situation|stuff|status|issues|matters))?$
        | laws?$
        | police\ ?[&/]\ ?laws?$ | police\ and\ (the\ )?law$
    """, re.I),
}


def absorbable(kind, title):
    """May this existing section be folded in under the new heading?"""
    t = re.sub(r"\[\[|\]\]", "", title).strip().rstrip(".")
    # "The Legality of Hitchhiking in the UAE", "Hitching in Uganda" — the
    # trailing "in <somewhere>" names the article's own subject and says
    # nothing the heading does not.
    t = re.sub(r"\s+in\s+(the\s+)?[A-Z][\w'’-]*(\s+[A-Z][\w'’-]*)*$", "", t)
    return bool(ABSORBABLE[kind].fullmatch(t.strip()))


BUCKETS = ("intro", "hitchhiking", "legality")


def smooth(plan, spans):
    """Never lift a sentence out of the middle of a paragraph.

    Splitting a paragraph at its first sentence reads fine — "Germany is a
    member state of the EU." leaves, the rest stays. Reaching into the middle
    of one for a single sentence does not: Vietnam's helmet paragraph lost
    "...they just don't want to be caught by police" from between two sentences
    that then no longer joined up. So within a paragraph a bucket has to be one
    contiguous run; a run with the same bucket on both sides of it is put back
    where it came from.
    """
    label = {}
    for b in BUCKETS:
        for i in plan[b]:
            label[i] = b
    by_para = {}
    for i, (_, _, p) in enumerate(spans):
        by_para.setdefault(p, []).append(i)

    moved = 0
    for ids in by_para.values():
        runs = []
        for i in ids:
            if runs and runs[-1][0] == label[i]:
                runs[-1][1].append(i)
            else:
                runs.append([label[i], [i]])
        for k in range(1, len(runs) - 1):
            if runs[k - 1][0] == runs[k + 1][0] and runs[k][0] != runs[k - 1][0]:
                for i in runs[k][1]:
                    label[i] = runs[k - 1][0]
                    moved += 1
    if not moved:
        return plan
    out = {b: [] for b in BUCKETS}
    for i in sorted(label):
        out[label[i]].append(i)
    return {**plan, **out}


FLOATER = re.compile(r"\{\{\s*(infobox|record-ride)", re.I)


def join_spans(lead, spans, ids):
    """The chosen spans, verbatim, in their original order.

    Between two spans that were adjacent in the source the original separator
    is reused, so a paragraph that survives intact survives byte for byte.
    Across a bucket boundary the separator is a paragraph break if the spans
    came from different paragraphs and a space if they did not.
    """
    out = []
    prev = None
    for i in sorted(ids):
        a, b, p = spans[i]
        if prev is not None:
            pa, pb, pp = spans[prev]
            out.append(lead[pb:a] if prev == i - 1
                       else ("\n\n" if pp != p else " "))
        out.append(lead[a:b])
        prev = i
    return "".join(out).strip()


def build(text, plan):
    """The restructured article, or the original if nothing moves."""
    doc = parse(text)
    lead, spans, sections = doc["lead"], doc["spans"], doc["sections"]
    # A cached plan is a list of span numbers, so it is only meaningful for the
    # article it was made from. Re-check it here: if the page has been edited
    # since, or the parser has changed under it, the numbers no longer line up
    # and reassembling anyway would silently scramble the text.
    validate(plan, len(spans), len(sections))
    # Two articles open with a line of prose and only then the infobox, so the
    # infobox lands in the lead as an assignable span. It floats the whole page
    # and belongs above the first heading whatever a classifier thinks of it.
    stuck = [i for i, (a, b, _) in enumerate(spans) if FLOATER.search(lead[a:b])]
    if stuck:
        plan = {**plan,
                "intro": sorted(set(plan["intro"]) | set(stuck)),
                "hitchhiking": [i for i in plan["hitchhiking"] if i not in stuck],
                "legality": [i for i in plan["legality"] if i not in stuck]}
    plan = smooth(plan, spans)

    intro = join_spans(lead, spans, plan["intro"])
    hitch = join_spans(lead, spans, plan["hitchhiking"])
    legal = join_spans(lead, spans, plan["legality"])

    absorbed = {}
    for key, field in (("hitch", "overview_section"), ("legal", "legality_section")):
        idx = plan.get(field)
        if idx is not None and absorbable(key, sections[idx]["title"]):
            absorbed[key] = sections[idx]

    if "hitch" in absorbed:
        hitch = "\n\n".join(x for x in (hitch, absorbed["hitch"]["body"].strip()) if x)
    if "legal" in absorbed:
        legal = "\n\n".join(x for x in (legal, absorbed["legal"]["body"].strip()) if x)

    # An article whose lead is nothing but hitchhiking advice would otherwise
    # open on a heading, with the bolded title stranded below it. Keep its
    # first sentence where the reader expects to find the article's name.
    if not intro and hitch:
        first = hitch.split("\n\n")[0]
        if "'''" in first and len(first) < 400:
            intro, hitch = first, hitch[len(first):].strip()

    # A heading and its first line are one block: this wiki writes
    # `== X ==\ntext`, and inserting a blank line there would put every
    # untouched section of every article into the diff for no reason.
    eq = "=" * doc["level"]
    blocks = [doc["prelude"].strip(), intro, doc["orphans"].strip()]
    if hitch:
        blocks.append(f"{eq} {HITCH_HEADING} {eq}\n{hitch}")
    if legal:
        blocks.append(f"{eq} {LEGAL_HEADING} {eq}\n{legal}")
    for sec in sections:
        if any(sec is a for a in absorbed.values()):
            continue
        blocks.append(f"{eq} {sec['title']} {eq}\n{sec['body'].strip()}".rstrip())

    return "\n\n".join(b for b in blocks if b).rstrip() + "\n"


# --------------------------------------------------------------------------
# the model's part: classification only
# --------------------------------------------------------------------------

PROMPT = """\
You are reorganising a hitchhiking wiki article about {title}.

Its opening text has been cut into numbered spans. Decide, for each span,
which of three parts of the article it belongs under. You are NOT rewriting
anything — you only sort span numbers into buckets.

  "intro"       Basic facts about the country itself: where it is, what it
                borders, capital, size, population, language, currency,
                climate, political status, general travel context. This part
                must stay SHORT — a couple of sentences. If a sentence is
                about hitchhiking, it does not belong here, however
                introductory it sounds.
  "hitchhiking" Anything about hitchhiking there: how well it works, what
                drivers are like, waiting times, roads and spots, signs,
                money expectations, safety, local customs, practical advice.
  "legality"    Only what bears on the law: whether hitchhiking is legal or
                illegal, where it is forbidden (motorways, tunnels, borders),
                police behaviour towards hitchhikers, ID and passport checks,
                fines, visas ONLY where they bear on hitchhiking. If a span
                is mostly practical advice that merely mentions police, put it
                in "hitchhiking".

Also pick, from the article's existing sections, at most one of each:

  "overview_section"  the section that is the article's GENERAL hitchhiking
                      overview and would read naturally as the body of a
                      section called "Hitchhiking" (titles like "Hitchhiking",
                      "Hitchhiking culture", "Hitchability"). NOT sections
                      about one route, one direction, one city, boats, or
                      trucks ("Hitchhiking out", "Hitchhiking in", "Hitchhiking
                      a boat"), and NOT lists of cities or links.
  "legality_section"  the section whose MAIN SUBJECT is the legal status of
                      hitchhiking — titles like "Legality", "Legal situation",
                      "Law". A section titled "Police" qualifies only if it is
                      chiefly about checks, papers, fines, or being moved on;
                      if it is chiefly about police being helpful to
                      hitchhikers, leave it where it is and answer null.

Use null when there is no such section. Never pick the same section for both.

Answer with JSON only, no prose, no code fence:
{{"intro": [], "hitchhiking": [], "legality": [],
  "overview_section": null, "legality_section": null}}

Every span number below must appear in exactly one of the three lists.

SPANS
{spans}

EXISTING SECTIONS
{sections}
"""


def cache_path(title):
    return os.path.join(CACHE, slug(title) + ".json")


def classify(key, model, title, text):
    doc = parse(text)
    if not doc["spans"]:
        return {"intro": [], "hitchhiking": [], "legality": [],
                "overview_section": None, "legality_section": None}
    lead = doc["lead"]
    spans = "\n".join(f"[{i}] {lead[a:b].strip()}"
                      for i, (a, b, _) in enumerate(doc["spans"]))
    sections = "\n".join(
        f"[{i}] == {s['title']} == :: {' '.join(s['body'].split())[:200]}"
        for i, s in enumerate(doc["sections"])) or "(none)"
    prompt = PROMPT.format(title=title, spans=spans, sections=sections)

    n = len(doc["spans"])
    last = None
    for attempt in range(3):
        raw = call_chat(key, model, [{"role": "user", "content": prompt}])
        try:
            plan = json.loads(re.sub(r"^```\w*|```$", "", raw.strip(),
                                     flags=re.M).strip())
            validate(plan, n, len(doc["sections"]))
            return plan
        except Exception as e:
            last = e
    raise RuntimeError(f"{title}: {last}")


def validate(plan, n_spans, n_sections):
    ids = []
    for k in ("intro", "hitchhiking", "legality"):
        if not isinstance(plan.get(k), list):
            raise ValueError(f"{k} missing")
        ids += [int(i) for i in plan[k]]
    if sorted(ids) != list(range(n_spans)):
        raise ValueError(f"spans not partitioned: got {sorted(ids)} "
                         f"want 0..{n_spans - 1}")
    picks = []
    for k in ("overview_section", "legality_section"):
        v = plan.get(k)
        if v is None:
            continue
        v = int(v)
        if not 0 <= v < n_sections:
            raise ValueError(f"{k} out of range: {v}")
        picks.append(v)
        plan[k] = v
    if len(picks) != len(set(picks)):
        raise ValueError("the same section picked twice")
    for k in ("intro", "hitchhiking", "legality"):
        plan[k] = sorted(int(i) for i in plan[k])
    return plan


# --------------------------------------------------------------------------
# invariants
# --------------------------------------------------------------------------

def is_noop(old, new):
    """Nothing moved — the only difference is whitespace.

    An article whose lead is three sentences about where the country is and
    nothing about hitchhiking has no section to gain, and saving it anyway
    would put a blank-line change in front of everyone watching the page.
    """
    return re.sub(r"\s+", " ", old).strip() == re.sub(r"\s+", " ", new).strip()


WORD_RE = re.compile(r"[^\W_]+", re.UNICODE)


def words(text):
    return WORD_RE.findall(text.lower())


def verify(title, old, new):
    """Every word survives, and no markup is left unbalanced.

    Word multiset equality is the strong claim: it catches a dropped sentence,
    a duplicated span and any silent rewording at once. The headings this tool
    adds and the ones it renames are the only permitted difference.
    """
    problems = []
    # Headings are excluded from both checks: renaming `== Legality ==` to
    # `== Legality of Hitchhiking ==` is the point, and a few articles head a
    # section `== [[Hitchhiking]] ==`, whose link the rename legitimately eats.
    old, new = (re.sub(SECTION_RE, "", t) for t in (old, new))
    old_w, new_w = words(old), words(new)
    if sorted(old_w) != sorted(new_w):
        import collections
        a, b = collections.Counter(old_w), collections.Counter(new_w)
        lost = list((a - b).elements())[:8]
        gained = list((b - a).elements())[:8]
        problems.append(f"text changed: lost {lost} gained {gained}")
    # Balance is checked against the source, not against zero: a handful of
    # these articles ship with a stray `[[` or an odd `'''` already, and this
    # tool's job is to not make that worse.
    for token in ("[[", "]]", "{{", "}}", "'''", "''"):
        if old.count(token) != new.count(token):
            problems.append(
                f"{token} count changed: {old.count(token)} -> {new.count(token)}")
    return problems


# --------------------------------------------------------------------------
# commands
# --------------------------------------------------------------------------

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


def targets(args):
    idx = load_index()
    if args.titles:
        return list(args.titles)
    return list(idx["countries"])


def cmd_plan(args):
    raw = load_raw(targets(args))
    rows = []
    for title, text in sorted(raw.items()):
        doc = parse(text)
        heads = [s["title"] for s in doc["sections"]]
        rows.append((len(doc["lead"].split()), title, len(doc["spans"]),
                     HITCH_HEADING in heads, LEGAL_HEADING in heads))
    rows.sort(reverse=True)
    print(f"{'lead words':>10} {'spans':>6}  {'H':1} {'L':1}  article")
    for w, title, n, h, l in rows:
        print(f"{w:>10} {n:>6}  {'x' if h else '-'} {'x' if l else '-'}  {title}")
    big = [r for r in rows if r[0] > 120]
    print(f"\n{len(rows)} articles, {len(big)} with a lead over 120 words, "
          f"{sum(1 for r in rows if r[3])} already have == {HITCH_HEADING} ==, "
          f"{sum(1 for r in rows if r[4])} == {LEGAL_HEADING} ==")


def cmd_classify(args):
    os.makedirs(CACHE, exist_ok=True)
    titles = targets(args)
    todo = [t for t in titles
            if args.force or not os.path.exists(cache_path(t))]
    if not todo:
        print("nothing to do")
        return
    raw = load_raw(todo)
    key = openai_key()
    done = failed = 0

    def run(title):
        plan = classify(key, args.model, title, raw[title])
        with open(cache_path(title), "w", encoding="utf-8") as f:
            json.dump({"title": title, "model": args.model, "plan": plan},
                      f, ensure_ascii=False, indent=1)

    with futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        jobs = {pool.submit(run, t): t for t in todo if t in raw}
        for fut in futures.as_completed(jobs):
            title = jobs[fut]
            try:
                fut.result()
                done += 1
            except Exception as e:
                failed += 1
                print(f"FAIL {title}: {e}", file=sys.stderr)
            if (done + failed) % 20 == 0:
                print(f"  {done + failed}/{len(jobs)}", file=sys.stderr)
    print(f"classified {done}, failed {failed}")


def cached(title):
    path = cache_path(title)
    if not os.path.exists(path):
        return None
    with open(path, encoding="utf-8") as f:
        return json.load(f)["plan"]


def cmd_render(args):
    raw = load_raw(targets(args))
    for title, text in sorted(raw.items()):
        plan = cached(title)
        if plan is None:
            print(f"-- {title}: not classified", file=sys.stderr)
            continue
        new = build(text, plan)
        if args.diff:
            print("\n".join(difflib.unified_diff(
                text.splitlines(), new.splitlines(),
                f"a/{title}", f"b/{title}", lineterm="", n=1)))
        else:
            print(new)
        for p in verify(title, text, new):
            print(f"!! {title}: {p}", file=sys.stderr)


def cmd_check(args):
    raw = load_raw(targets(args))
    bad = changed = same = 0
    for title, text in sorted(raw.items()):
        plan = cached(title)
        if plan is None:
            print(f"?? {title}: not classified")
            continue
        try:
            new = build(text, plan)
        except Exception as e:
            print(f"!! {title}: {e}")
            bad += 1
            continue
        problems = verify(title, text, new)
        if problems:
            bad += 1
            for p in problems:
                print(f"!! {title}: {p}")
        elif is_noop(text, new):
            same += 1
        else:
            changed += 1
    print(f"\n{changed} would change, {same} already fine, {bad} rejected")
    return 1 if bad else 0


def cmd_push(args):
    raw = load_raw(targets(args))
    wrote = skipped = bad = 0
    for title, text in sorted(raw.items()):
        plan = cached(title)
        if plan is None:
            continue
        new = build(text, plan)
        problems = verify(title, text, new)
        if problems:
            bad += 1
            print(f"!! {title}: {'; '.join(problems)} — skipped")
            continue
        if is_noop(text, new):
            skipped += 1
            continue
        if args.dry_run:
            print(f"would edit {title}")
            wrote += 1
            continue
        put_wikitext("en", title, new, SUMMARY)
        wrote += 1
        print(f"edited {title}")
        time.sleep(0.2)
    print(f"\n{wrote} edited, {skipped} unchanged, {bad} rejected")
    return 1 if bad else 0


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)

    def common(p):
        p.add_argument("titles", nargs="*", help="default: every country")
        p.add_argument("--all", action="store_true", help="(default)")

    for name, fn in (("plan", cmd_plan), ("render", cmd_render),
                     ("check", cmd_check), ("push", cmd_push),
                     ("classify", cmd_classify)):
        p = sub.add_parser(name)
        common(p)
        p.set_defaults(func=fn)
        if name == "classify":
            p.add_argument("-j", "--jobs", type=int, default=6)
            p.add_argument("--model", default=DEFAULT_MODEL)
            p.add_argument("--force", action="store_true")
        if name == "render":
            p.add_argument("--diff", action="store_true")
        if name == "push":
            p.add_argument("-n", "--dry-run", action="store_true")

    args = ap.parse_args()
    sys.exit(args.func(args) or 0)


if __name__ == "__main__":
    main()
