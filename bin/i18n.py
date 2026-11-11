#!/usr/bin/env python3
"""i18n tooling without gettext binaries.

  python3 bin/i18n.py pot              -> languages/wp-affiliate-captain.pot
  python3 bin/i18n.py build de_DE      -> .po/.mo from languages/de_DE.json + JS JSON
  python3 bin/i18n.py missing de_DE    -> list untranslated msgids
"""
import hashlib, json, os, re, struct, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAIN = "wp-affiliate-captain"
FUNCS = r"(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_x|_n)"
SQ = r"'((?:[^'\\]|\\.)*)'"
DQ = r'"((?:[^"\\]|\\.)*)"'


def unescape_sq(s):
    return s.replace("\\'", "'").replace("\\\\", "\\")


def unescape_dq(s):
    return s.encode("utf-8").decode("unicode_escape").encode("latin-1").decode("utf-8").replace("\\$", "$")


def php_strings():
    found = {}
    pat = re.compile(FUNCS + r"\(\s*(" + SQ + "|" + DQ + r")(?:\s*,\s*(" + SQ + "|" + DQ + r"))?\s*,\s*'" + DOMAIN + "'")
    pat_n = re.compile(r"_n\(\s*" + SQ + r"\s*,\s*" + SQ + r"\s*,")
    for base in ("src", "."):
        for dirpath, _, files in os.walk(os.path.join(ROOT, base)):
            if base == "." and dirpath != ROOT:
                continue
            for f in files:
                if not f.endswith(".php"):
                    continue
                path = os.path.join(dirpath, f)
                rel = os.path.relpath(path, ROOT)
                text = open(path, encoding="utf-8").read()
                for m in pat_n.finditer(text):
                    key = (unescape_sq(m.group(1)), unescape_sq(m.group(2)))
                    found.setdefault(key, set()).add(rel)
                for m in pat.finditer(text):
                    if text[m.start():m.start() + 3] == "_n(":
                        continue
                    if m.group(2) is not None:
                        s = unescape_sq(m.group(2))
                    else:
                        s = "\\n".join(x for x in [m.group(3)]).replace("\\n", "\n")
                        s = s.replace('\\"', '"').replace("\\$", "$")
                    found.setdefault((s, None), set()).add(rel)
    return found


def js_strings():
    path = os.path.join(ROOT, "assets/js/blocks.js")
    text = open(path, encoding="utf-8").read()
    out = []
    for m in re.finditer(r"__\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'" + DOMAIN + "'", text):
        s = unescape_sq(m.group(1))
        if s not in out:
            out.append(s)
    return out


def po_escape(s):
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def write_pot():
    strings = php_strings()
    lines = ['msgid ""', 'msgstr ""', '"Content-Type: text/plain; charset=UTF-8\\n"', '"X-Domain: %s\\n"' % DOMAIN, ""]
    for (s, plural), refs in sorted(strings.items(), key=lambda kv: sorted(kv[1])[0]):
        lines.append("#: " + " ".join(sorted(refs)))
        lines.append('msgid "%s"' % po_escape(s))
        if plural:
            lines += ['msgid_plural "%s"' % po_escape(plural), 'msgstr[0] ""', 'msgstr[1] ""', ""]
        else:
            lines += ['msgstr ""', ""]
    for s in js_strings():
        lines += ["#: assets/js/blocks.js", 'msgid "%s"' % po_escape(s), 'msgstr ""', ""]
    open(os.path.join(ROOT, "languages", DOMAIN + ".pot"), "w", encoding="utf-8").write("\n".join(lines))
    return len(strings) + len(js_strings())


def write_mo(path, entries):
    """entries: dict msgid(str, plural joined by \\0) -> msgstr (plural forms joined by \\0)."""
    keys = sorted(entries)
    ids = b""
    strs = b""
    offsets = []
    for k in keys:
        kb, vb = k.encode("utf-8"), entries[k].encode("utf-8")
        offsets.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b"\0"
        strs += vb + b"\0"
    n = len(keys)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + len(ids)
    koffsets, voffsets = [], []
    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]
        voffsets += [l2, o2 + valuestart]
    out = struct.pack("Iiiiiii", 0x950412DE, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    out += struct.pack("%di" % len(koffsets), *koffsets)
    out += struct.pack("%di" % len(voffsets), *voffsets)
    out += ids + strs
    open(path, "wb").write(out)


def build(locale):
    tr = json.load(open(os.path.join(ROOT, "languages", locale + ".json"), encoding="utf-8"))
    header = "Content-Type: text/plain; charset=UTF-8\nLanguage: %s\nPlural-Forms: nplurals=2; plural=(n != 1);\nX-Domain: %s\n" % (locale, DOMAIN)
    entries = {"": header}
    po = ['msgid ""', 'msgstr ""'] + ['"%s\\n"' % l for l in header.strip().split("\n")] + [""]
    missing = []
    for (s, plural), refs in php_strings().items():
        if plural:
            t = tr.get(s)
            if not isinstance(t, list):
                missing.append(s)
                continue
            entries[s + "\0" + plural] = t[0] + "\0" + t[1]
            po += ['msgid "%s"' % po_escape(s), 'msgid_plural "%s"' % po_escape(plural), 'msgstr[0] "%s"' % po_escape(t[0]), 'msgstr[1] "%s"' % po_escape(t[1]), ""]
        else:
            if s not in tr:
                missing.append(s)
                continue
            entries[s] = tr[s]
            po += ['msgid "%s"' % po_escape(s), 'msgstr "%s"' % po_escape(tr[s]), ""]
    js = {}
    for s in js_strings():
        if s in tr:
            js[s] = [tr[s]]
        else:
            missing.append(s)
    base = os.path.join(ROOT, "languages", "%s-%s" % (DOMAIN, locale))
    open(base + ".po", "w", encoding="utf-8").write("\n".join(po))
    write_mo(base + ".mo", entries)
    md5 = hashlib.md5(b"assets/js/blocks.js").hexdigest()
    jed = {"domain": "messages", "locale_data": {"messages": dict({"": {"domain": "messages", "lang": locale, "plural-forms": "nplurals=2; plural=(n != 1);"}}, **js)}}
    json.dump(jed, open(base + "-" + md5 + ".json", "w", encoding="utf-8"), ensure_ascii=False)
    return missing


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "pot"
    if cmd == "pot":
        print(write_pot(), "strings")
    elif cmd == "missing":
        tr = json.load(open(os.path.join(ROOT, "languages", sys.argv[2] + ".json"), encoding="utf-8")) if os.path.exists(os.path.join(ROOT, "languages", sys.argv[2] + ".json")) else {}
        for (s, p), _ in php_strings().items():
            if s not in tr:
                print(json.dumps(s, ensure_ascii=False))
        for s in js_strings():
            if s not in tr:
                print(json.dumps(s, ensure_ascii=False))
    elif cmd == "build":
        missing = build(sys.argv[2])
        print("missing:", len(missing))
        for m in missing:
            print(" ", json.dumps(m, ensure_ascii=False))
