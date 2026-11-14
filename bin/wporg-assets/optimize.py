#!/usr/bin/env python3
"""Shrinks the PNGs in .wordpress-org/ (palette quantisation with Pillow, 256 colours).

    python3 bin/wporg-assets/optimize.py

Screenshots are quantised without dithering (flat UI colours, sharp text), banners with dithering
(gradients), icons keep their transparency. A file is only replaced when the result is smaller.
"""
import os
import sys

from PIL import Image

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '.wordpress-org')


def optimise(path):
    name = os.path.basename(path)
    before = os.path.getsize(path)
    img = Image.open(path)
    img.load()
    if name.startswith('icon-'):
        out = img.convert('RGBA').quantize(256, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.FLOYDSTEINBERG)
    elif name.startswith('banner-'):
        out = img.convert('RGB').quantize(256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.FLOYDSTEINBERG)
    else:
        out = img.convert('RGB').quantize(256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
    tmp = path + '.tmp.png'
    out.save(tmp, optimize=True)
    after = os.path.getsize(tmp)
    if after < before:
        os.replace(tmp, path)
    else:
        os.remove(tmp)
        after = before
    print('%-24s %8d -> %8d bytes' % (name, before, after))


def main():
    files = sorted(f for f in os.listdir(ROOT) if f.endswith('.png'))
    if not files:
        sys.exit('no PNG files in ' + ROOT)
    for f in files:
        optimise(os.path.join(ROOT, f))


if __name__ == '__main__':
    main()
