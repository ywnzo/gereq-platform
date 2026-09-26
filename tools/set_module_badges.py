#!/usr/bin/env python3
"""Attach a consistent set of GitLab project badges to every GEREQ module."""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULES = ROOT / "modules"
API = os.environ.get("GITLAB_URL", "https://git.gereq.cz").rstrip("/") + "/api/v4"
TOKEN = os.environ.get("GITLAB_TOKEN", "").strip()


def php_manifest(path: Path) -> dict[str, str]:
    out = subprocess.check_output(
        [
            "php",
            "-r",
            '$m=require $argv[1]; echo json_encode(["id"=>$m["id"],"name"=>$m["name"],"version"=>$m["version"],"repository"=>$m["repository"]??("gereq/module-".$m["id"])]);',
            str(path),
        ],
        text=True,
    )
    return json.loads(out)


def req(method: str, path: str, data: dict | None = None) -> tuple[int, object]:
    url = API + path
    body = None
    headers = {"PRIVATE-TOKEN": TOKEN, "Content-Type": "application/json"}
    if data is not None:
        body = json.dumps(data).encode()
    request = urllib.request.Request(url, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=30) as resp:
            raw = resp.read().decode()
            return resp.status, json.loads(raw) if raw else None
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode()
        try:
            parsed = json.loads(raw) if raw else {"message": str(exc)}
        except json.JSONDecodeError:
            parsed = {"message": raw[:300]}
        return exc.code, parsed


def shields(label: str, message: str, color: str) -> str:
    # shields.io static badge
    enc = urllib.parse.quote(f"{label}-{message}-{color}", safe="")
    return f"https://img.shields.io/badge/{enc}?style=flat-square"


def badge_defs(mid: str, name: str, version: str, repo: str) -> list[dict[str, str]]:
    project_url = f"https://git.gereq.cz/{repo}"
    tags_url = f"{project_url}/-/tags"
    ver = version if version.startswith("v") else f"v{version}"
    return [
        {
            "name": "GEREQ",
            "link_url": "https://git.gereq.cz/gereq",
            "image_url": shields("GEREQ", "module", "1a3020"),
        },
        {
            "name": "Version",
            "link_url": tags_url,
            "image_url": shields("version", ver, "3dd6c6"),
        },
        {
            "name": "Module",
            "link_url": project_url,
            "image_url": shields("module", mid, "6eb6ff"),
        },
        {
            "name": "Visibility",
            "link_url": project_url,
            "image_url": shields("visibility", "private", "f08a6a"),
        },
    ]


def sync_project(repo: str, badges: list[dict[str, str]]) -> None:
    enc = urllib.parse.quote(repo, safe="")
    code, existing = req("GET", f"/projects/{enc}/badges")
    if code != 200:
        raise RuntimeError(f"list badges {repo}: HTTP {code} {existing}")
    assert isinstance(existing, list)
    for b in existing:
        bid = b["id"]
        dcode, _ = req("DELETE", f"/projects/{enc}/badges/{bid}")
        if dcode not in (204, 200):
            print(f"  warn delete badge {bid}: {dcode}", file=sys.stderr)
    for b in badges:
        code, created = req("POST", f"/projects/{enc}/badges", b)
        if code not in (201, 200):
            raise RuntimeError(f"create badge {b['name']} on {repo}: HTTP {code} {created}")


def patch_readme(module_dir: Path, mid: str, name: str, version: str, repo: str) -> bool:
    readme = module_dir / "README.md"
    badges = badge_defs(mid, name, version, repo)
    lines = []
    for b in badges:
        lines.append(f"[![{b['name']}]({b['image_url']})]({b['link_url']})")
    block = " ".join(lines) + "\n\n"

    if readme.exists():
        text = readme.read_text(encoding="utf-8")
        # Strip previous badge line(s) at top (shields.io / img.shields)
        text = re.sub(
            r"^(?:\s*(?:\[!\[.*?\]\(https://img\.shields\.io/[^)]+\)\]\([^)]+\)\s*)+\n+)+",
            "",
            text,
            count=1,
            flags=re.MULTILINE,
        )
        if text.startswith("#"):
            # Insert badges after first heading
            parts = text.split("\n", 1)
            heading = parts[0]
            rest = parts[1] if len(parts) > 1 else ""
            new = heading + "\n\n" + block + rest.lstrip("\n")
        else:
            new = block + text
    else:
        new = f"# {name}\n\n{block}GEREQ module `{mid}` — v{version}\n"

    if readme.exists() and readme.read_text(encoding="utf-8") == new:
        return False
    readme.write_text(new, encoding="utf-8")
    return True


def main() -> int:
    if not TOKEN:
        print("GITLAB_TOKEN required", file=sys.stderr)
        return 1

    ok = fail = 0
    for manifest in sorted(MODULES.glob("*/module.php")):
        meta = php_manifest(manifest)
        mid, name, version, repo = meta["id"], meta["name"], meta["version"], meta["repository"]
        badges = badge_defs(mid, name, version, repo)
        try:
            sync_project(repo, badges)
            changed = patch_readme(manifest.parent, mid, name, version, repo)
            print(f"OK {repo} badges={len(badges)} readme={'updated' if changed else 'same'}")
            ok += 1
        except Exception as exc:  # noqa: BLE001 — report and continue
            print(f"FAIL {repo}: {exc}", file=sys.stderr)
            fail += 1

    print(f"SUMMARY ok={ok} fail={fail}")
    return 0 if fail == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
