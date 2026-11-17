#!/usr/bin/env python3
"""Builds docs/functional-audit/COOLIFY_PLATFORM_FUNCTIONAL_ATLAS.pdf.

    python3 build.py                # HTML + PDF
    python3 build.py --preview 7    # PNG of page 7 in build/preview/ for visual review

Only reads build/evidence.json (run collect_evidence.py first) and writes inside
docs/functional-audit/. Requires Google Chrome for the headless print.
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
AUDIT = HERE.parent
BUILD = AUDIT / "build"
PDF = AUDIT / "COOLIFY_PLATFORM_FUNCTIONAL_ATLAS.pdf"
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

sys.path.insert(0, str(HERE))

import content  # noqa: E402
from layout import Book  # noqa: E402


def chrome(*args: str) -> None:
    subprocess.run([CHROME, "--headless=new", "--disable-gpu", "--no-first-run", "--hide-scrollbars", *args],
                   check=True, capture_output=True)


def main() -> None:
    evidence = json.loads((BUILD / "evidence.json").read_text())
    book = Book()
    content.build(book, evidence)
    html = book.render()
    BUILD.mkdir(parents=True, exist_ok=True)
    out_html = BUILD / "atlas.html"
    out_html.write_text(html)

    if "--preview" in sys.argv:
        page = int(sys.argv[sys.argv.index("--preview") + 1])
        preview = BUILD / "preview"
        preview.mkdir(exist_ok=True)
        single = html.replace("<body>", f"<body><style>.page{{display:none}} .page:nth-of-type({page}){{display:block}}</style>", 1)
        single_file = preview / f"page-{page}.html"
        single_file.write_text(single)
        chrome(f"--screenshot={preview / f'page-{page}.png'}", "--window-size=1588,1123", single_file.as_uri())
        print(preview / f"page-{page}.png")
        return

    chrome("--no-pdf-header-footer", f"--print-to-pdf={PDF}", out_html.as_uri())
    print(f"{PDF} ({len(book.pages)} pages)")


if __name__ == "__main__":
    main()
