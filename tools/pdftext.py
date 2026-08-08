"""Minimal PDF text extractor.

Enough to grep a statute out of a government gazette PDF on a host with no
pdftotext and no pip. Inflates the content streams, then reads the strings out
of the Tj/TJ operators. Gazette PDFs are usually CID-encoded — the strings are
hex glyph ids, not text — so the ToUnicode CMaps are parsed first and used to
map them back. No layout, no columns; the output is one long line to grep.
"""
import re
import sys
import zlib


def inflated(raw):
    for m in re.finditer(rb'stream\r?\n', raw):
        end = raw.find(b'endstream', m.end())
        if end < 0:
            continue
        try:
            yield zlib.decompress(raw[m.end():end])
        except zlib.error:
            continue


def cmap(blob):
    """glyph id -> unicode, from one ToUnicode CMap stream."""
    out = {}
    for m in re.finditer(rb'beginbfchar(.*?)endbfchar', blob, re.S):
        for src, dst in re.findall(rb'<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>', m.group(1)):
            out[int(src, 16)] = _utf16(dst)
    for m in re.finditer(rb'beginbfrange(.*?)endbfrange', blob, re.S):
        body = m.group(1)
        for lo, hi, dst in re.findall(
                rb'<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>', body):
            base = int(dst, 16)
            for i in range(int(lo, 16), int(hi, 16) + 1):
                out[i] = chr(base + i - int(lo, 16))
    return out


def _utf16(hexbytes):
    b = bytes.fromhex(hexbytes.decode())
    try:
        return b.decode('utf-16-be')
    except UnicodeDecodeError:
        return ''


PLAUSIBLE = re.compile(r'[0-9A-Za-zÀ-ž §.,;:()§/–-]')


def _score(text):
    """How much of this looks like running text in a Latin-script statute."""
    if not text:
        return 0.0
    return sum(bool(PLAUSIBLE.match(c)) for c in text) / len(text)


def _decode(hexes, table):
    return ''.join(
        table.get(int(h[i:i + 4], 16), '')
        for h in hexes for i in range(0, len(h) - 3, 4))


def extract(path):
    """Text of the PDF, one long line.

    A gazette PDF usually embeds several subset fonts, each with its own
    ToUnicode CMap, and glyph ids collide between them — merging the tables
    turns half the document into mojibake. Rather than parse the object graph
    to learn which font a given `Tf` selects, each stream is decoded with every
    CMap and the result that looks most like running text wins. With two or
    three fonts that is cheap, and it needs nothing but the streams themselves.
    """
    raw = open(path, 'rb').read()
    blobs = list(inflated(raw))

    tables = [t for t in (cmap(b) for b in blobs
                          if b'beginbfchar' in b or b'beginbfrange' in b) if t]

    parts = []
    for b in blobs:
        if b'Tj' not in b and b'TJ' not in b:
            continue
        hexes, lits = [], []
        for m in re.finditer(rb'<([0-9A-Fa-f]+)>|\((?:\\.|[^\\()])*\)', b):
            tok = m.group(0)
            if tok.startswith(b'<'):
                hexes.append(m.group(1))
            else:
                s = tok[1:-1]
                s = re.sub(rb'\\([()\\])', rb'\1', s)
                s = re.sub(rb'\\(\d{1,3})',
                           lambda k: bytes([int(k.group(1), 8) & 0xFF]), s)
                lits.append(s.decode('latin-1'))
        if hexes and tables:
            best = max((_decode(hexes, t) for t in tables), key=_score)
            parts.append(best)
        parts.extend(lits)
        parts.append(' ')
    return re.sub(r'\s+', ' ', ''.join(parts))


if __name__ == '__main__':
    t = extract(sys.argv[1])
    print('chars:', len(t))
    for kw in sys.argv[2:]:
        hits = list(re.finditer(kw, t, re.I))
        print(f'## {kw}: {len(hits)}')
        for m in hits[:3]:
            print('  ...', t[max(0, m.start() - 400):m.start() + 400], '\n')
