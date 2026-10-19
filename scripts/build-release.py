#!/usr/bin/env python3
"""Build the installable plugin ZIP using only the Python standard library."""
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile
import hashlib
import re

root = Path(__file__).resolve().parent.parent
version = re.search(r"^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$", (root / "telegram-backup.php").read_text(), re.M)
if not version:
    raise SystemExit("Missing stable plugin version")
output = root / "dist"
output.mkdir(exist_ok=True)
target = output / f"telegram-backup-{version.group(1)}.zip"
excluded = {".git", ".github", "dist", "tests", "scripts", "node_modules", "vendor", "__pycache__"}
with ZipFile(target, "w", ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(root.rglob("*")):
        relative = path.relative_to(root)
        if not path.is_file() or path.is_symlink() or any(part in excluded or part.startswith(".") for part in relative.parts):
            continue
        if path.suffix in {".zip", ".pyc", ".pvlt", ".sql"} or path.name == "wp-config.php":
            continue
        archive.write(path, "telegram-backup/" + relative.as_posix())
with ZipFile(target) as archive:
    if archive.testzip() is not None or "telegram-backup/telegram-backup.php" not in archive.namelist():
        raise SystemExit("Invalid plugin archive")
checksum = hashlib.sha256(target.read_bytes()).hexdigest()
(output / "SHA256SUMS.txt").write_text(f"{checksum}  {target.name}\n")
print(f"Built {target.name} ({target.stat().st_size:,} bytes), SHA256 {checksum}")
