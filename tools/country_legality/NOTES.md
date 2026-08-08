# Sourcing notes

Which countries are done, and — more usefully — which ones resisted and why, so
the next pass starts from the failures rather than rediscovering them.

## Done (31)

Austria, Belgium, Brazil, Croatia, Czech Republic, Denmark, England, Finland,
France, Germany, Greece, Hungary, Ireland, Italy, Latvia, Netherlands, Northern
Ireland, Norway, Poland, Portugal, Romania, Russia, Scotland, Serbia, Slovakia,
Spain, Sweden, Switzerland, Ukraine, United Kingdom, Wales.

`python3 tools/country_legality.py plan all` prints the live state; this list is
just the roster of block files.

## Blocked, with what was tried

| country | source | what happened |
| --- | --- | --- |
| Slovenia | pisrs.si, uradni-list.si | JS-only; the `npb` PDF path 404s |
| Bulgaria | lex.bg, dv.parliament.bg, mvr.bg | 403 to this host |
| Estonia | riigiteataja.ee (incl. `/en/eli/...`) | JS-only, `.pdf` suffix returns HTML |
| Lithuania | e-tar.lt 403; e-seimas `TAIS.239817` | wrong act — that id is an EU association agreement |
| Georgia | matsne.gov.ge | full text fetches fine, but the motorway pedestrian rule was not found by keyword; needs the article located properly |
| Turkey | mevzuat.gov.tr (html/pdf/doc) | connection times out |
| Malta | legislation.mt | JS-only; `/eng/pdf` serves HTML |
| Kosovo, Bosnia, Montenegro, North Macedonia, Albania | gzk.rks-gov.net, paragraf.ba, katalogpropisa.me, slvesnik.com.mk, qbz.gov.al | JS shells or wrong document ids |
| Belarus, Moldova | pravo.by, legis.md | 404 / 403 |
| Singapore | sso.agc.gov.sg | reachable, but `RTA1961-R20` and `-R5` are the wrong instruments; the expressway rules are elsewhere |
| New Zealand | legislation.govt.nz | returns 202 with an empty body |
| Iceland, Luxembourg, Mexico, Morocco, South Africa, India, Argentina, Chile, Japan | various | 403, empty, or JS-only on the URLs tried |

## What works

`curl` with a browser UA and `--http1.1` gets: gesetze-im-internet.de, boe.es,
normattiva.it, wetten.overheid.nl, fedlex.admin.ch (the `filestore/...-de-html.html`
form), ris.bka.gv.at, irishstatutebook.ie, legislation.gov.uk (`/data.xht?view=snippet`),
lovdata.no, e-sbirka.gov.cz, slov-lex.sk (PDF only), zakon.rada.gov.ua (`/print`),
narodne-novine.nn.hr, likumi.lv, planalto.gov.br, finlex.fi (`/assets/*.xml`).

Legifrance needs WebFetch — Cloudflare 403s curl. legislatie.just.ro and njt.hu
are unreachable from this host entirely but are live for normal users, so their
URLs are cited on the strength of search-engine indexing.

`pdftext.py` in the session scratchpad inflates gazette PDFs and decodes the
ToUnicode CMaps; it is what got Slovakia out of slov-lex. Worth moving into
`tools/` if PDF-only gazettes keep coming up. There is no `pdftotext` and no
`pip` on this host.

## Rules of the road for this work

- A search-engine summary is a lead, never a citation. Confirmed wrong at least
  three times: Spain's article is 125 not 121, France's is R421-2 not R412-7,
  and a WebFetch of the Latvian regulation returned a "quote" containing two
  non-Latvian words.
- Check that the law has not been replaced. Greece's whole code became
  ν. 5209/2025; Belgium's 1975 code expires 1 September 2026.
- If the source cannot be read, the country is deferred, not guessed.
