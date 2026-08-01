#!/usr/bin/env python3
"""Template the family's main pages instead of hand-copying them per language.

Every language wiki's Main Page/Hauptseite/Accueil/… used to be an independent
wikitext page, translated by hand from the English one and then never kept in
sync: `tools/build_main_pages.py extract` on the pre-existing pages showed 29 of
the 34 language wikis already carried the *same* structure (header, nav links,
newsletter box, Events/News boxes, gallery, related projects) with only strings
translated — plus small drift, like some missing the trailing `__NOTOC__`. The
other five (he, it, ro, ru, uk) had genuinely different, older-style front
pages (their own box layouts, `{{Eventi}}`/`{{Notizie}}` instead of
`{{events}}`/`{{news}}` on `it`, `ru`'s a plain redirect to `АвтостопВики`) and
were brought onto the shared structure by hand-translating the handful of
strings the old pages didn't already have an equivalent for — see each
language's `tools/main_page_i18n/<lang>.json` history for what was reused
from the old page vs. freshly translated. `uk` needed no new translation at
all: it already had the full standard layout plus two genuinely extra
community boxes, preserved via the `extra_sections` field.

This script makes the English structure in `tools/main_page_template.wikitext`
the single source of truth, and keeps only the translated strings per language
in `tools/main_page_i18n/<lang>.json`. Structural changes (add a section,
restyle a class) happen once in the template and `render`+`push` propagate
them everywhere; string changes happen once in a language's JSON file.

Usage:
    python3 tools/build_main_pages.py extract <lang>   # pull strings out of the
                                                         # live page into i18n/<lang>.json
    python3 tools/build_main_pages.py render <lang>     # print the generated wikitext
    python3 tools/build_main_pages.py push <lang> [--dry-run]
    python3 tools/build_main_pages.py push all [--dry-run]
"""

import argparse
import json
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.abspath(__file__))
TEMPLATE_PATH = os.path.join(ROOT, "main_page_template.wikitext")
I18N_DIR = os.path.join(ROOT, "main_page_i18n")
CONTAINER = "hitchwiki-mediawiki"

# Every language wiki's main page is templated.
TEMPLATED_LANGUAGES = (
    "en ar bg cs da de el es et fa fi fr he hr hu it ja ka lt lv mn nl no pl pt "
    "ro ru sk sl sr sv tr uk zh"
).split()

FIELDS = (
    "tagline", "signal_text", "ingress", "nav_links", "races_header",
    "register_text", "events_label", "events_edit_label", "news_label",
    "news_edit_label", "gallery_header", "caption_iran", "caption_italy",
    "caption_moscow", "caption_taklamakan", "caption_berlin_sign",
    "caption_guitar", "caption_split_dubrovnik", "upload_cta",
    "extra_sections", "related_header", "nomad_desc", "trash_desc",
    "trustroots_desc",
)

EXTRACT_RE = re.compile(
    r"<ul id=\"frontpage-header\">\n"
    r"<li>\n"
    r"<h1>\[\[Hitchwiki:About\|(?P<tagline>.*?)\]\]</h1>\n"
    r"(?:<!--.*?-->\n)?"
    r"<h1>\[https://signal\.group/#CjQKIGgw5-FMZ1Sq67CL50LKCkZg-6i0KCDuS24S4uBDyb5IEhAmnOqOcim9eSMm-TJFozyg <u> (?P<signal_text>.*?) </u>\]</h1>\n"
    r"<div class=\"ingress\">(?P<ingress>.*?)\n"
    r"</div>\n"
    r"<span class=\"first_links\">(?P<nav_links>.*?)\n"
    r"</li>\n"
    r"</ul>\n"
    r"\n"
    r"<div style=\"text-align: center;\">\n"
    r"===(?P<races_header>.*?)===\n"
    r"\n"
    r"'''(?P<register_text>.*?)'''\n"
    r"</div>\n"
    r"<div class=\"frontpage_col\"><div class=\"col_content\">\n"
    r"<div class=\"frontpage_events frontpage_box\">\n"
    r"== \[\[Events\|<i class=\"fa fa-lg fa-thumbs-up\"></i> (?P<events_label>.*?)\]\] <small class=\"frontpage-meta-links plainlinks pull-right text-muted\">\[\{\{fullurl:Template:Events\|action=edit\}\} <i class=\"fa fa-plus\"></i> (?P<events_edit_label>.*?)\]</small>==\n"
    r"\{\{events\}\}\n"
    r"</div></div></div>\n"
    r"<div class=\"frontpage_col\"><div class=\"col_content\">\n"
    r"<div class=\"frontpage_news frontpage_box\">\n"
    r"== \[\[News\|<i class=\"fa fa-lg fa-bullhorn\"></i> (?P<news_label>.*?)\]\] <small class=\"frontpage-meta-links plainlinks pull-right text-muted\">\[\{\{fullurl:Template:News\|action=edit\}\} <i class=\"fa fa-plus\"></i> (?P<news_edit_label>.*?)\]</small>==\n"
    r"\{\{news\}\}\n"
    r"</div></div></div>\n"
    r"\n"
    r"<div class=\"frontpage_gallery frontpage_box\" style=\"clear: both; margin-top: 20px;\">\n"
    r"== <i class=\"fa fa-lg fa-camera\"></i> (?P<gallery_header>.*?) ==\n"
    r"\n"
    r"<gallery mode=\"packed\" heights=\"160px\">\n"
    r"File:Hitchhiking in Iran\.jpg\|(?P<caption_iran>.*?)\n"
    r"File:Hitchhiking in Italy\.jpg\|(?P<caption_italy>.*?)\n"
    r"File:Hitchhiking Moscow Red Square\.jpeg\|(?P<caption_moscow>.*?)\n"
    r"File:Taklamakan Desert, China\.jpg\|(?P<caption_taklamakan>.*?)\n"
    r"File:Tramprennen berlin sign\.jpg\|(?P<caption_berlin_sign>.*?)\n"
    r"File:Tramprennen guitar\.jpg\|(?P<caption_guitar>.*?)\n"
    r"File:Tramprennen split dubrovnik\.jpg\|(?P<caption_split_dubrovnik>.*?)\n"
    r"</gallery>\n"
    r"\n"
    r"<div style=\"text-align: center; margin-top: 15px; font-weight: bold; font-size: 1\.1em;\">\n"
    r"(?P<upload_cta>.*?)\n"
    r"</div>\n"
    r"</div>\n"
    r"(?P<extra_sections>.*)"  # greedy: backtracks to the LAST matching related-projects box
    r"<div class=\"frontpage_ptg frontpage_box\">\n"
    r"== (?P<related_header>.*?) ==\n"
    r"\* \[\[:nomad:\|Nomadwiki\]\] (?P<nomad_desc>.*?)\n"
    r"\* \[\[:trash:\|Trashwiki\]\] (?P<trash_desc>.*?)\n"
    r"\* \[\[:trustroots:\|Trustroots\]\] (?P<trustroots_desc>.*?)\n"
    r"</div>",
    re.DOTALL,
)


def get_main_page_title(lang):
    out = subprocess.run(
        [
            "docker", "exec", CONTAINER, "curl", "-s",
            f"http://localhost/{lang}/api.php?action=query&meta=allmessages"
            "&ammessages=mainpage&format=json",
        ],
        capture_output=True, text=True, check=True,
    ).stdout
    return json.loads(out)["query"]["allmessages"][0]["*"]


def get_wikitext(lang, title):
    out = subprocess.run(
        [
            "docker", "exec", CONTAINER, "php",
            "/var/www/html/maintenance/run.php", "getText", f"--wiki={lang}", title,
        ],
        capture_output=True, text=True, check=True,
    ).stdout
    return out


def i18n_path(lang):
    return os.path.join(I18N_DIR, f"{lang}.json")


def cmd_extract(lang):
    title = get_main_page_title(lang)
    wikitext = get_wikitext(lang, title)
    m = EXTRACT_RE.search(wikitext)
    if not m:
        print(f"{lang}: does not match the standard template shape, skipping", file=sys.stderr)
        return 1
    data = {field: m.group(field) for field in FIELDS}
    data["gallery_captions"] = {
        "iran": data.pop("caption_iran"),
        "italy": data.pop("caption_italy"),
        "moscow": data.pop("caption_moscow"),
        "taklamakan": data.pop("caption_taklamakan"),
        "berlin_sign": data.pop("caption_berlin_sign"),
        "guitar": data.pop("caption_guitar"),
        "split_dubrovnik": data.pop("caption_split_dubrovnik"),
    }
    os.makedirs(I18N_DIR, exist_ok=True)
    with open(i18n_path(lang), "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2, sort_keys=True)
        f.write("\n")
    print(f"{lang}: wrote {i18n_path(lang)}")
    return 0


def render(lang):
    with open(TEMPLATE_PATH, encoding="utf-8") as f:
        template = f.read()
    with open(i18n_path(lang), encoding="utf-8") as f:
        data = json.load(f)
    captions = data["gallery_captions"]
    tokens = {
        "TAGLINE": data["tagline"],
        "SIGNAL_TEXT": data["signal_text"],
        "INGRESS": data["ingress"],
        "NAV_LINKS": data["nav_links"],
        "RACES_HEADER": data["races_header"],
        "REGISTER_TEXT": data["register_text"],
        "EVENTS_LABEL": data["events_label"],
        "EVENTS_EDIT_LABEL": data["events_edit_label"],
        "NEWS_LABEL": data["news_label"],
        "NEWS_EDIT_LABEL": data["news_edit_label"],
        "GALLERY_HEADER": data["gallery_header"],
        "CAPTION_IRAN": captions["iran"],
        "CAPTION_ITALY": captions["italy"],
        "CAPTION_MOSCOW": captions["moscow"],
        "CAPTION_TAKLAMAKAN": captions["taklamakan"],
        "CAPTION_BERLIN_SIGN": captions["berlin_sign"],
        "CAPTION_GUITAR": captions["guitar"],
        "CAPTION_SPLIT_DUBROVNIK": captions["split_dubrovnik"],
        "UPLOAD_CTA": data["upload_cta"],
        "EXTRA_SECTIONS": data.get("extra_sections", "\n"),
        "RELATED_HEADER": data["related_header"],
        "NOMAD_DESC": data["nomad_desc"],
        "TRASH_DESC": data["trash_desc"],
        "TRUSTROOTS_DESC": data["trustroots_desc"],
    }
    out = template
    for key, value in tokens.items():
        out = out.replace(f"%%{key}%%", value)
    if "%%" in out:
        missing = re.findall(r"%%[A-Z_]+%%", out)
        raise ValueError(f"{lang}: unresolved placeholders {missing}")
    return out


def cmd_render(lang):
    sys.stdout.write(render(lang))
    return 0


def cmd_push(lang, dry_run):
    languages = TEMPLATED_LANGUAGES if lang == "all" else [lang]
    for l in languages:
        title = get_main_page_title(l)
        wikitext = render(l)
        if dry_run:
            print(f"--- {l} ({title}) ---")
            print(wikitext)
            continue
        summary = "Sync main page from shared template (tools/build_main_pages.py)"
        subprocess.run(
            [
                "docker", "exec", "-i", CONTAINER, "php",
                "/var/www/html/maintenance/edit.php", f"--wiki={l}",
                "--bot", "--summary", summary, title,
            ],
            input=wikitext, text=True, check=True,
        )
        print(f"{l}: pushed to {title!r}")
    return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    p_extract = sub.add_parser("extract", help="pull i18n strings from the live page")
    p_extract.add_argument("lang")

    p_render = sub.add_parser("render", help="print the generated wikitext")
    p_render.add_argument("lang")

    p_push = sub.add_parser("push", help="write the generated wikitext to the wiki")
    p_push.add_argument("lang", help="language code, or 'all' for every templated wiki")
    p_push.add_argument("--dry-run", action="store_true")

    args = parser.parse_args()
    if args.command == "extract":
        return cmd_extract(args.lang)
    if args.command == "render":
        return cmd_render(args.lang)
    if args.command == "push":
        return cmd_push(args.lang, args.dry_run)


if __name__ == "__main__":
    sys.exit(main())
