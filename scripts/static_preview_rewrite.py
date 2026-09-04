#!/usr/bin/env python3
"""Rewrite a wget mirror of the wp-env site into a self-contained static preview.

Called by scripts/export-static-preview.sh; operates in place on the mirror root
passed as argv[1]. Reads BASE_PATH and CALENDAR_INITIAL_DATE from the env.
"""

import json
import os
import re
import sys
from pathlib import Path

SITE = "http://localhost:8888"
ROOT = Path(sys.argv[1])
BASE = os.environ.get("BASE_PATH", "/ruben-dance").rstrip("/")
CALENDAR_INITIAL_DATE = os.environ.get("CALENDAR_INITIAL_DATE", "")

TEXT_SUFFIXES = {".html", ".css", ".js", ".json", ".xml", ".txt"}

BANNER = (
    '<div style="background:#1b1b1b;color:#fff;font:500 13px/1.5 system-ui,sans-serif;'
    'padding:10px 16px;text-align:center">'
    "Statický náhled designu — formuláře, přihlášení a platby zde nefungují. "
    '<a href="https://github.com/TechVo/ruben-dance" style="color:#fff">Zdrojový kód na GitHubu</a>'
    "</div>"
)

# <head> cruft that only points back at a WordPress install that isn't there.
HEAD_CRUFT = [
    re.compile(r'<link rel="https://api\.w\.org/"[^>]*/?>\s*'),
    re.compile(r'<link rel="alternate"[^>]*application/json[^>]*/?>\s*'),
    re.compile(r'<link rel="alternate"[^>]*oembed[^>]*/?>\s*'),
    re.compile(r'<link rel="EditURI"[^>]*/?>\s*'),
    re.compile(r'<link rel="wlwmanifest"[^>]*/?>\s*'),
    re.compile(r"<link rel='shortlink'[^>]*/?>\s*"),
    re.compile(r'<link rel="shortlink"[^>]*/?>\s*'),
    re.compile(r"<meta name=\"generator\"[^>]*/?>\s*"),
]


def url_to_static(url: str) -> str:
    """Turn an absolute local WordPress URL into a root-relative static path."""
    path = url[len(SITE):] or "/"
    path = path.split("?", 1)[0].split("#", 1)[0]
    if path.endswith("/"):
        path += "index.html"
    return BASE + path


def remove_junk() -> int:
    """Drop the query-string duplicates wget picked up from shortlinks and
    login redirects — the canonical page is already mirrored."""
    removed = 0
    for path in list(ROOT.rglob("*")):
        if not path.is_file():
            continue
        name = path.name
        if re.match(r"index\.html@p=\d+\.html$", name) or "@redirect_to=" in name or name == "xmlrpc.php@rsd":
            path.unlink()
            removed += 1
    return removed


def rewrite_lessons_json() -> None:
    for path in (ROOT / "rd-data").glob("lessons-*.json"):
        rows = json.loads(path.read_text(encoding="utf-8"))
        for row in rows:
            if isinstance(row.get("url"), str) and row["url"].startswith(SITE):
                row["url"] = url_to_static(row["url"])
        path.write_text(json.dumps(rows, ensure_ascii=False), encoding="utf-8")


def patch_calendar_js() -> None:
    """Let the FullCalendar bundle honour an initialDate handed to it from the
    page, so the preview opens on a month the fixture data actually covers."""
    js = ROOT / "wp-content/plugins/ruben-dance/public/assets/calendar.js"
    if not js.exists():
        return
    text = js.read_text(encoding="utf-8")
    needle = "var calendar = new FullCalendar.Calendar( container, {\n"
    if needle in text and "rdCalendarL10n.initialDate" not in text:
        text = text.replace(
            needle,
            needle + "\t\t\tinitialDate: rdCalendarL10n.initialDate || undefined,\n",
            1,
        )
        js.write_text(text, encoding="utf-8")


def rewrite_html(path: Path, text: str) -> str:
    for pattern in HEAD_CRUFT:
        text = pattern.sub("", text)

    # Point the calendar at the JSON snapshot instead of the REST route. The
    # query string the JS appends is ignored by any static host, so the whole
    # snapshot comes back and FullCalendar range-filters it client-side.
    if '"restUrl"' in text:
        lang = "en" if "/en/" in path.as_posix() else "cs"
        text = re.sub(
            r'"restUrl":"[^"]*"',
            f'"restUrl":"{BASE}/rd-data/lessons-{lang}.json"',
            text,
        )
        if CALENDAR_INITIAL_DATE:
            text = text.replace(
                '"restUrl":',
                f'"initialDate":"{CALENDAR_INITIAL_DATE}","restUrl":',
                1,
            )

    # The enroll page's "log in first" links carry a redirect_to; that variant of
    # the login page is a duplicate we drop, and the redirect means nothing here.
    text = re.sub(r'(prihlaseni|login)/index\.html@redirect_to=[^"\']*', r'\1/index.html', text)

    if "<body" in text and "Statický náhled designu" not in text:
        text = re.sub(r"(<body[^>]*>)", r"\1" + BANNER, text, count=1)

    return text


def rewrite_files() -> int:
    touched = 0
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        original = text

        if path.suffix.lower() == ".html":
            text = rewrite_html(path, text)

        # Anything still pointing at the dev server: map real pages onto their
        # static counterparts, and neutralise the rest (REST routes, xmlrpc)
        # rather than leaving a dead localhost link in a public page.
        if SITE in text:
            text = re.sub(
                re.escape(SITE) + r"/(?!wp-json|xmlrpc)[^\"'\s<>)]*",
                lambda m: url_to_static(m.group(0)),
                text,
            )
            text = re.sub(re.escape(SITE) + r"/[^\"'\s<>)]*", "#", text)

        if text != original:
            path.write_text(text, encoding="utf-8")
            touched += 1
    return touched


def write_host_files() -> None:
    # Paths contain wp-includes/wp-content; keep GitHub Pages from running Jekyll.
    (ROOT / ".nojekyll").write_text("", encoding="utf-8")
    (ROOT / "robots.txt").write_text(
        "# Design preview of a site that is not live yet — keep it out of search results.\n"
        "User-agent: *\nDisallow: /\n",
        encoding="utf-8",
    )
    index = ROOT / "index.html"
    if index.exists():
        (ROOT / "404.html").write_text(index.read_text(encoding="utf-8"), encoding="utf-8")


def main() -> None:
    print(f"    removed {remove_junk()} duplicate files")
    rewrite_lessons_json()
    patch_calendar_js()
    print(f"    rewrote {rewrite_files()} files")
    write_host_files()

    leftovers = [
        p.relative_to(ROOT).as_posix()
        for p in ROOT.rglob("*")
        if p.is_file() and p.suffix.lower() in TEXT_SUFFIXES
        and SITE in p.read_text(encoding="utf-8", errors="ignore")
    ]
    if leftovers:
        print(f"    WARNING: {len(leftovers)} files still reference {SITE}: {leftovers[:5]}")


if __name__ == "__main__":
    main()
