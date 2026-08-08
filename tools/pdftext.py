"""Text out of a PDF, for hosts with no pdftotext and no pip.

Government gazettes are often the only readable form of a statute — the modern
legal portals tend to be JavaScript applications that serve a shell to `curl` —
so getting text out of a PDF is what unblocks a country. This does the minimum
to make one greppable: inflate the objects, resolve each font to its ToUnicode
CMap, then walk the content streams tracking the current font so hex strings
decode with the right table.

Resolving fonts properly is the part that matters. A gazette embeds a dozen or
more subset fonts whose glyph ids all start at 1 and mean different letters, so
a single merged table, or a guess-per-stream, turns most of the document into
mojibake — convincing-looking mojibake, which is worse than none.

No layout: the output is one long line, which is all a grep needs.

    python3 tools/pdftext.py file.pdf                 # char count
    python3 tools/pdftext.py file.pdf 'pešec' 'avtocest'   # grep with context
"""
import re
import sys
import zlib

OBJ = re.compile(rb'(\d+)\s+(\d+)\s+obj\b(.*?)\bendobj', re.S)
STREAM = re.compile(rb'stream\r?\n(.*?)\r?\nendstream', re.S)


def _inflate(data):
    try:
        return zlib.decompress(data)
    except zlib.error:
        try:                       # some writers leave a stray trailing byte
            return zlib.decompressobj().decompress(data)
        except zlib.error:
            return None


def objects(raw):
    """objnum -> (dict_bytes, stream_bytes_or_None), following object streams."""
    out = {}
    for m in OBJ.finditer(raw):
        num, body = int(m.group(1)), m.group(3)
        s = STREAM.search(body)
        data = None
        if s:
            data = s.group(1)
            if b'/FlateDecode' in body[:s.start()]:
                data = _inflate(data)
        out[num] = (body if not s else body[:s.start()], data)

    # PDF 1.5+ packs most dicts into /ObjStm containers.
    for num, (d, data) in list(out.items()):
        if b'/ObjStm' not in d or not data:
            continue
        n = int(re.search(rb'/N\s+(\d+)', d).group(1))
        first = int(re.search(rb'/First\s+(\d+)', d).group(1))
        header = data[:first].split()
        for i in range(n):
            onum, off = int(header[2 * i]), int(header[2 * i + 1])
            end = int(header[2 * i + 3]) + first if i + 1 < n else len(data)
            out.setdefault(onum, (data[first + off:end], None))
    return out


def cmap(blob):
    """glyph code -> unicode, from one ToUnicode CMap stream."""
    out = {}
    for m in re.finditer(rb'beginbfchar(.*?)endbfchar', blob, re.S):
        for src, dst in re.findall(rb'<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>', m.group(1)):
            out[int(src, 16)] = _utf16(dst)
    for m in re.finditer(rb'beginbfrange(.*?)endbfrange', blob, re.S):
        body = m.group(1)
        for lo, hi, dst in re.findall(
                rb'<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>', body):
            base, start = int(dst, 16), int(lo, 16)
            for i in range(start, int(hi, 16) + 1):
                out[i] = chr(base + i - start)
        for lo, hi, arr in re.findall(
                rb'<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]', body, re.S):
            dsts = re.findall(rb'<([0-9A-Fa-f]+)>', arr)
            for i, d in enumerate(dsts):
                out[int(lo, 16) + i] = _utf16(d)
    return out


def _utf16(hexbytes):
    b = bytes.fromhex(hexbytes.decode())
    try:
        return b.decode('utf-16-be')
    except UnicodeDecodeError:
        return b.decode('latin-1', 'replace')


def _fonts(objs):
    """objnum of a font -> its cmap."""
    out = {}
    for num, (d, _) in objs.items():
        m = re.search(rb'/ToUnicode\s+(\d+)\s+\d+\s+R', d)
        if not m:
            continue
        tgt = objs.get(int(m.group(1)))
        if tgt and tgt[1]:
            out[num] = cmap(tgt[1])
    return out


def _resources(d, objs, fonts):
    """resource name (b'F1') -> cmap, for one page's /Resources."""
    m = re.search(rb'/Font\s*(\d+)\s+\d+\s+R', d)
    if m:
        tgt = objs.get(int(m.group(1)))
        d = tgt[0] if tgt else b''
    m = re.search(rb'/Font\s*<<(.*?)>>', d, re.S)
    if not m:
        return {}
    return {name: fonts[int(ref)]
            for name, ref in re.findall(rb'/([^\s/<>]+)\s+(\d+)\s+\d+\s+R', m.group(1))
            if int(ref) in fonts}


def _decode(content, table):
    """Text of one content stream, switching table on each Tf."""
    parts = []
    cur = {}
    for m in re.finditer(
            rb'/([^\s/<>\[\]]+)\s+[\d.]+\s+Tf'          # 1: font select
            rb'|<([0-9A-Fa-f]+)>'                       # 2: hex string
            rb'|\((?:\\.|[^\\()])*\)'                   # 0: literal string
            rb'|(TJ|Tj|T\*|Td|TD)', content):           # 3: show / newline
        if m.group(1) is not None:
            cur = table.get(m.group(1), cur)
        elif m.group(2) is not None:
            h = m.group(2)
            if cur:
                parts.append(''.join(cur.get(int(h[i:i + 4], 16), '')
                                     for i in range(0, len(h) - 3, 4)))
        elif m.group(3) is not None:
            parts.append(' ')
        else:
            s = m.group(0)[1:-1]
            s = re.sub(rb'\\([()\\])', rb'\1', s)
            s = re.sub(rb'\\(\d{1,3})',
                       lambda k: bytes([int(k.group(1), 8) & 0xFF]), s)
            parts.append(s.decode('latin-1'))
    return ''.join(parts)


PLAUSIBLE = re.compile(r'[0-9A-Za-zÀ-ɏЀ-ӿͰ-ϿႠ-ჿ §.,;:()/–-]')


def _score(text):
    if not text:
        return 0.0
    return sum(bool(PLAUSIBLE.match(c)) for c in text) / len(text)


def _salvage(objs, fonts):
    """Fallback for PDFs whose page tree this does not understand.

    Some writers put no `/Type /Page` where it can be found, or reach the
    content by a route not implemented here, and the structured pass then
    yields nothing. Decoding every content stream with each known CMap in turn
    and keeping whichever output looks most like running text recovers the
    document. It is a guess per stream rather than per font, so it can still
    garble a page that mixes fonts — good enough to grep, not to quote from
    without checking.
    """
    tables = list(fonts.values())
    parts = []
    for _, data in objs.values():
        if not data or (b'Tj' not in data and b'TJ' not in data):
            continue
        best = max((_decode(data, {n: t for n in _names(data)})
                    for t in tables), key=_score, default='')
        parts.append(best)
    return parts


def _names(content):
    return set(re.findall(rb'/([^\s/<>\[\]]+)\s+[\d.]+\s+Tf', content)) or {b'F1'}


def extract(path):
    raw = open(path, 'rb').read()
    objs = objects(raw)
    fonts = _fonts(objs)

    parts = []
    for num, (d, data) in objs.items():
        if b'/Type' not in d or b'/Page' not in d:
            continue
        table = _resources(d, objs, fonts)
        if not table:
            continue
        for m in re.finditer(rb'/Contents\s+(?:(\d+)\s+\d+\s+R|\[(.*?)\])', d, re.S):
            refs = ([m.group(1)] if m.group(1)
                    else re.findall(rb'(\d+)\s+\d+\s+R', m.group(2)))
            for r in refs:
                tgt = objs.get(int(r))
                if tgt and tgt[1]:
                    parts.append(_decode(tgt[1], table))

    if sum(len(p) for p in parts) < 2000 and fonts:
        parts = _salvage(objs, fonts)
    return re.sub(r'\s+', ' ', ' '.join(parts))


if __name__ == '__main__':
    t = extract(sys.argv[1])
    print('chars:', len(t))
    for kw in sys.argv[2:]:
        hits = list(re.finditer(kw, t, re.I))
        print(f'## {kw}: {len(hits)}')
        for m in hits[:3]:
            print('  ...', t[max(0, m.start() - 400):m.start() + 400], '\n')
