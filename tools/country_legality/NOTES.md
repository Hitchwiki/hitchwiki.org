# Sourcing notes

Which countries are done, and — more usefully — which ones resisted and why, so
the next pass starts from the failures rather than rediscovering them.

## Done (37)

Austria, Belgium, Bosnia and Herzegovina, Brazil, Bulgaria, Croatia, Czech Republic, Denmark, England, Estonia, Finland, France, Germany, Greece, Hungary, Iceland, Ireland, Italy, Latvia, Lithuania, Netherlands, Northern Ireland, Norway, Poland, Portugal, Romania, Russia, Scotland, Serbia, Slovakia, Slovenia, Spain, Sweden, Switzerland, Ukraine, United Kingdom, Wales.

`python3 tools/country_legality.py plan all` prints the live state; this list is
just the roster of block files.

## Blocked, with what was tried

| country | source | what happened |
| --- | --- | --- |
| Georgia | matsne.gov.ge | full text fetches fine, but the motorway pedestrian rule was not found by keyword; needs the article located properly |
| Turkey | mevzuat.gov.tr (html/pdf/doc) | connection times out |
| Malta | legislation.mt | JS-only; `/eng/pdf` serves HTML |
| Kosovo, Montenegro, North Macedonia, Albania | gzk.rks-gov.net, katalogpropisa.me, slvesnik.com.mk, qbz.gov.al | JS shells or wrong document ids |
| Belarus, Moldova | pravo.by, legis.md | 404 / 403 |
| Singapore | sso.agc.gov.sg | reachable, but `RTA1961-R20` and `-R5` are the wrong instruments; the expressway rules are elsewhere |
| New Zealand | legislation.govt.nz | returns 202 with an empty body |
| Luxembourg, Mexico, Morocco, South Africa, India, Argentina, Chile, Japan | various | 403, empty, or JS-only on the URLs tried |

### Solved since

- **Slovenia** — the Uradni list issue PDF (`uradni-list.si/_pdf/2010/Ur/u2010109.pdf`)
  carries the whole of ZPrCP. Issue PDFs are the way into that gazette.
- **Bulgaria** — lex.bg 403s, but the Road Infrastructure Agency republishes the
  consolidated ЗДвП at `rta.government.bg/upload/15545/zdvp.pdf`.
- **Estonia** — Riigi Teataja serves only a JS shell and its `.pdf` suffix returns
  HTML. § 66 was read from a dated verbatim reprint of the consolidated text
  (17.07.2024) and the citation points at the register's `?leiaKehtiv=` URL,
  which always resolves to the version in force. Worth re-checking against the
  register itself if a route into it is ever found.

- **Lithuania** — `e-seimas.lrs.lt/rs/legalact/TAD/<hash>/` serves the full KET;
  the `/portal/legalAct/` and e-tar.lt routes do not.
- **Bosnia** — the Parliamentary Assembly serves the act as a PDF from
  `parlament.ba/law/DownloadDocument?lawDocumentId=…`.
- **Iceland** — `adverts.stjornartidindi.is/A_nr_<n>_<year>.pdf` is the gazette
  PDF; althingi.is 403s this host.

**The reliable method**: search for the statute's real PDF or document URL and
fetch that. Guessing URL patterns failed 14 times out of 14 in one round;
searched URLs worked immediately in the next.

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
