#!/usr/bin/env python3
"""
Add Apache 2.0 license headers to all source files in the Ashchan project.

Supports: PHP, Shell, Python, SQL, YAML, CSS, JS, HTML, Dockerfile, Makefile,
          PHPStan neon configs, JSON (skipped — no standard comment syntax).

Run from the project root:
    python3 add_license_headers.py
"""

import os
import sys

# ── License header text blocks ──────────────────────────────────────────────

# For languages using // or  /* */ or #  comments we adapt the block.

HASH_HEADER = """\
# Copyright 2026 txrx-byte
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.
"""

SLASH_HEADER = """\
// Copyright 2026 txrx-byte
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
"""

BLOCK_HEADER = """\
/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
"""

HTML_HEADER = """\
<!--
  Copyright 2026 txrx-byte

  Licensed under the Apache License, Version 2.0 (the "License");
  you may not use this file except in compliance with the License.
  You may obtain a copy of the License at

      http://www.apache.org/licenses/LICENSE-2.0

  Unless required by applicable law or agreed to in writing, software
  distributed under the License is distributed on an "AS IS" BASIS,
  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
  See the License for the specific language governing permissions and
  limitations under the License.
-->
"""

SQL_HEADER = """\
-- Copyright 2026 txrx-byte
--
-- Licensed under the Apache License, Version 2.0 (the "License");
-- you may not use this file except in compliance with the License.
-- You may obtain a copy of the License at
--
--     http://www.apache.org/licenses/LICENSE-2.0
--
-- Unless required by applicable law or agreed to in writing, software
-- distributed under the License is distributed on an "AS IS" BASIS,
-- WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
-- See the License for the specific language governing permissions and
-- limitations under the License.
"""

SENTINEL = "Licensed under the Apache License"

# Directories to skip entirely
SKIP_DIRS = {
    "vendor", "node_modules", ".git",
}

# File patterns to skip
SKIP_FILES = {
    "composer.lock",
}


def already_has_header(content: str) -> bool:
    """Check if the file already contains the Apache 2.0 header."""
    # Check first 2000 chars to be safe
    return SENTINEL in content[:2000]


def add_header_php(content: str) -> str:
    """Add header to PHP files, after <?php and declare(strict_types=1);"""
    lines = content.split("\n")
    insert_after = 0

    for i, line in enumerate(lines):
        stripped = line.strip()
        if stripped == "<?php":
            insert_after = i + 1
        elif stripped.startswith("declare(strict_types"):
            insert_after = i + 1
            break
        elif stripped and not stripped.startswith("<?") and not stripped.startswith("//") and not stripped.startswith("#"):
            # Stop looking once we hit real code
            if i > 5:
                break

    # If we found <?php, insert after it
    header = BLOCK_HEADER.rstrip()
    lines.insert(insert_after, "")
    lines.insert(insert_after + 1, header)
    lines.insert(insert_after + 2, "")
    return "\n".join(lines)


def add_header_shell(content: str) -> str:
    """Add header to shell scripts, after shebang line."""
    lines = content.split("\n")
    insert_after = 0

    if lines and lines[0].startswith("#!"):
        insert_after = 1

    header = HASH_HEADER.rstrip()
    lines.insert(insert_after, "")
    lines.insert(insert_after + 1, header)
    lines.insert(insert_after + 2, "")
    return "\n".join(lines)


def add_header_python(content: str) -> str:
    """Add header to Python files, after shebang and docstring."""
    lines = content.split("\n")
    insert_after = 0

    if lines and lines[0].startswith("#!"):
        insert_after = 1

    header = HASH_HEADER.rstrip()
    lines.insert(insert_after, "")
    lines.insert(insert_after + 1, header)
    lines.insert(insert_after + 2, "")
    return "\n".join(lines)


def add_header_sql(content: str) -> str:
    """Add SQL comment header at the top."""
    header = SQL_HEADER.rstrip()
    return header + "\n\n" + content


def add_header_yaml(content: str) -> str:
    """Add hash-comment header to YAML files."""
    header = HASH_HEADER.rstrip()
    return header + "\n\n" + content


def add_header_css(content: str) -> str:
    """Add block comment header to CSS files."""
    header = BLOCK_HEADER.rstrip()
    return header + "\n\n" + content


def add_header_js(content: str) -> str:
    """Add block comment header to JS files."""
    # JS files might have 'use strict'; at the top
    lines = content.split("\n")
    insert_after = 0

    for i, line in enumerate(lines):
        stripped = line.strip()
        if stripped in ("'use strict';", '"use strict";'):
            insert_after = i + 1
            break
        elif stripped and i > 2:
            break

    header = BLOCK_HEADER.rstrip()
    lines.insert(insert_after, "")
    lines.insert(insert_after + 1, header)
    if insert_after == 0:
        # No 'use strict' found, just prepend
        return header + "\n\n" + content
    lines.insert(insert_after + 2, "")
    return "\n".join(lines)


def add_header_html(content: str) -> str:
    """Add HTML comment header."""
    # If starts with <!DOCTYPE or <html, insert after that line
    lines = content.split("\n")
    insert_after = 0

    if lines and (lines[0].strip().lower().startswith("<!doctype") or lines[0].strip().lower().startswith("<html")):
        insert_after = 1

    header = HTML_HEADER.rstrip()
    lines.insert(insert_after, header)
    lines.insert(insert_after + 1, "")
    return "\n".join(lines)


def add_header_dockerfile(content: str) -> str:
    """Add hash-comment header to Dockerfiles."""
    header = HASH_HEADER.rstrip()
    return header + "\n\n" + content


def add_header_makefile(content: str) -> str:
    """Add hash-comment header to Makefiles."""
    header = HASH_HEADER.rstrip()
    return header + "\n\n" + content


def add_header_neon(content: str) -> str:
    """Add hash-comment header to PHPStan .neon files."""
    header = HASH_HEADER.rstrip()
    return header + "\n\n" + content


def process_file(filepath: str) -> bool:
    """Process a single file. Returns True if modified."""
    basename = os.path.basename(filepath)
    _, ext = os.path.splitext(basename)
    ext = ext.lower()

    # Skip lock files and non-commentable formats
    if basename in SKIP_FILES:
        return False

    # JSON has no comment syntax — skip
    if ext == ".json":
        return False

    try:
        with open(filepath, "r", encoding="utf-8", errors="replace") as f:
            content = f.read()
    except (OSError, IOError):
        return False

    if not content.strip():
        return False

    if already_has_header(content):
        return False

    # Dispatch by file type
    if ext == ".php":
        new_content = add_header_php(content)
    elif ext in (".sh",):
        new_content = add_header_shell(content)
    elif ext == ".py":
        new_content = add_header_python(content)
    elif ext == ".sql":
        new_content = add_header_sql(content)
    elif ext in (".yml", ".yaml"):
        new_content = add_header_yaml(content)
    elif ext == ".css":
        new_content = add_header_css(content)
    elif ext == ".js":
        new_content = add_header_js(content)
    elif ext == ".html":
        new_content = add_header_html(content)
    elif ext == ".neon":
        new_content = add_header_neon(content)
    elif basename.startswith("Dockerfile"):
        new_content = add_header_dockerfile(content)
    elif basename == "Makefile":
        new_content = add_header_makefile(content)
    else:
        return False

    with open(filepath, "w", encoding="utf-8") as f:
        f.write(new_content)

    return True


def main():
    root = os.path.dirname(os.path.abspath(__file__))
    modified = 0
    skipped = 0
    already = 0
    errors = []

    extensions = {
        ".php", ".sh", ".py", ".sql", ".yml", ".yaml",
        ".css", ".js", ".html", ".neon",
    }
    special_names = {"Dockerfile", "Makefile"}

    for dirpath, dirnames, filenames in os.walk(root):
        # Prune skipped directories
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]

        for fname in sorted(filenames):
            _, ext = os.path.splitext(fname)
            ext = ext.lower()

            is_target = (ext in extensions) or (fname in special_names) or fname.startswith("Dockerfile")

            if not is_target:
                continue

            filepath = os.path.join(dirpath, fname)
            rel = os.path.relpath(filepath, root)

            try:
                with open(filepath, "r", encoding="utf-8", errors="replace") as f:
                    peek = f.read(2000)
                if already_has_header(peek):
                    already += 1
                    continue

                if process_file(filepath):
                    modified += 1
                    print(f"  ✓ {rel}")
                else:
                    skipped += 1
            except Exception as e:
                errors.append((rel, str(e)))

    print(f"\n{'='*60}")
    print(f"  Modified : {modified}")
    print(f"  Skipped  : {skipped} (JSON or empty)")
    print(f"  Already  : {already}")
    if errors:
        print(f"  Errors   : {len(errors)}")
        for path, err in errors:
            print(f"    ✗ {path}: {err}")
    print(f"{'='*60}")


if __name__ == "__main__":
    main()
