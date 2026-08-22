#!/usr/bin/env python3
"""Canonical zip packager for WP MCP Suite.

Produces dist/wpmcp-<version>.zip with a `wpmcp/` root folder and
forward-slash entry separators (safe for every WordPress extractor).
Used by humans and by CI (.github/workflows/release.yml).
"""
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SKIP_DIRS = {".wp-core", ".wp-env", ".git", "node_modules", "dist", "tests", "docs", ".opencode", ".github"}
SKIP_FILES = {".wp-env.json", "README.md", "build.ps1"}


def read_version() -> str:
    header = io_read(os.path.join(ROOT, "wpmcp.php"))
    m = re.search(r"Version:\s*([0-9]+\.[0-9]+\.[0-9]+)", header)
    if not m:
        sys.exit("Could not parse version from wpmcp.php")
    return m.group(1)


def io_read(path: str) -> str:
    with open(path, "r", encoding="utf-8") as fh:
        return fh.read()


def build() -> str:
    version = read_version()
    dest_dir = os.path.join(ROOT, "dist")
    os.makedirs(dest_dir, exist_ok=True)
    dest = os.path.join(dest_dir, f"wpmcp-{version}.zip")
    if os.path.exists(dest):
        os.remove(dest)

    count = 0
    with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as z:
        for dp, dn, fn in os.walk(ROOT):
            dn[:] = [d for d in dn if d not in SKIP_DIRS]
            for f in fn:
                if f in SKIP_FILES:
                    continue
                full = os.path.join(dp, f)
                arc = "wpmcp/" + os.path.relpath(full, ROOT).replace(os.sep, "/")
                zi = zipfile.ZipInfo(arc, date_time=(2026, 1, 1, 0, 0, 0))
                zi.compress_type = zipfile.ZIP_DEFLATED
                zi.external_attr = (0o644 | 0o100000) << 16
                with open(full, "rb") as fh:
                    z.writestr(zi, fh.read())
                count += 1

    size = os.path.getsize(dest)
    print(f"Built {dest} ({count} entries, {size} bytes)")
    return dest


if __name__ == "__main__":
    build()
