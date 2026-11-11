#!/usr/bin/env python3
"""Optional dev check: renders QR matrices from src/Promotion/Qr.php and decodes
them with zbarimg (apt install zbar-tools; pip install pillow).
Run from the repository root: python3 tests/qr_decode_check.py"""
import os, subprocess, sys, tempfile
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DUMP = r'''<?php
require $argv[1];
foreach ( WPAffiliateCaptain\Promotion\Qr::matrix( $argv[2] ) as $row ) {
	echo implode( '', array_map( function ( $b ) { return $b ? '1' : '0'; }, $row ) ), "\n";
}
'''
texts = ["A", "https://example.com/?partner=ANNA", "Ümlaut ß € test",
         "https://shop.example.de/kurse/sbf-see/?partner=SEGELSCHULE-NORD&utm_campaign=sommer-2026&utm_source=partner"] + \
        ["x" * n for n in (14, 26, 42, 62, 84, 106, 122, 152, 180, 213)]
fails = 0
with tempfile.TemporaryDirectory() as tmp:
    dump = os.path.join(tmp, "dump.php")
    open(dump, "w").write(DUMP)
    png = os.path.join(tmp, "qr.png")
    for t in texts:
        rows = subprocess.run(["php", dump, os.path.join(ROOT, "src/Promotion/Qr.php"), t], capture_output=True, text=True, check=True).stdout.split()
        n, s, q = len(rows), 8, 4
        img = Image.new("L", ((n + 2 * q) * s,) * 2, 255)
        for y, r in enumerate(rows):
            for x, c in enumerate(r):
                if c == "1":
                    img.paste(0, ((x + q) * s, (y + q) * s, (x + q + 1) * s, (y + q + 1) * s))
        img.save(png)
        got = subprocess.run(["zbarimg", "-q", "--raw", "-Sbinary", png], capture_output=True).stdout.rstrip(b"\n").decode("utf-8", "replace")
        ok = got == t
        fails += 0 if ok else 1
        print("OK  " if ok else "FAIL", f"v{(n - 17) // 4:<2}", len(t.encode()), "bytes")
print(f"{len(texts)} QR codes, {fails} failures")
sys.exit(1 if fails else 0)
