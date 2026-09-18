# Markdown Linting

## Table of Contents

- [Current State](#current-state)
- [Optional: Running markdownlint](#optional-running-markdownlint)
- [Character Encoding in Markdown Files](#character-encoding-in-markdown-files)
- [Notes](#notes)

## Current State

This repository has no markdownlint configuration and no markdown lint step in CI. Keep markdown consistent with the existing files (`README.md`, `AGENTS.md`, the skill files under `.ai/skills/`, and the pull request template) by following the `woocommerce-markdown` skill:

- ATX headings (`#`), one H1 per file, no skipped levels
- `-` for unordered lists, 4 spaces for nested items
- Blank lines around headings, lists, and code blocks
- Fenced code blocks always declare a language
- Files end with a single newline, no trailing whitespace

## Optional: Running markdownlint

If you want an automated check, run markdownlint-cli through `npx` with its default rules. Do not add a configuration file or a dependency for it unless asked.

```bash
# Auto-fix most issues
npx markdownlint-cli --fix --disable MD013 -- path/to/file.md

# Report what remains
npx markdownlint-cli --disable MD013 -- path/to/file.md
```

`MD013` (line length) is disabled in these examples because the existing files use long lines freely; do not rewrap prose to satisfy it.

Issues that usually need a manual fix:

| Code | Issue | Fix |
|------|-------|-----|
| **MD007** | List indentation | Use 4 spaces for nested items |
| **MD031** | Code blocks need blank lines | Add a blank line above and below the fence |
| **MD032** | Lists need blank lines | Add a blank line before and after the list |
| **MD036** | Emphasis used as a heading | Use a real heading |
| **MD040** | Code block without language | Add `bash`, `php`, `typescript`, `json`, `text`, ... |
| **MD047** | Missing trailing newline | End the file with one newline |

## Character Encoding in Markdown Files

**Never allow control characters or null bytes into markdown files.**

Use UTF-8 box-drawing characters for directory trees, not ASCII art:

```markdown
✅ CORRECT - UTF-8 box-drawing:
.ai/skills/
├── woocommerce-backend-dev/
│   ├── SKILL.md
│   └── file-entities.md
└── woocommerce-dev-cycle/
    └── SKILL.md

❌ WRONG - ASCII art or spaces:
.ai/skills/
+-- woocommerce-backend-dev/
|   +-- SKILL.md
```

Check the encoding after editing a file that contains such characters:

```bash
file path/to/file.md
# Should show: "UTF-8 text" or "ASCII text"
# NEVER: "data"
```

If a file becomes corrupted (shows as "data" instead of text):

```bash
# Remove control characters and null bytes
tr -d '\000-\037' < file.md > file.clean.md && mv file.clean.md file.md
file file.md
```

## Notes

- `changelog.txt` is plain text in the WooCommerce extension format, not markdown; see the `woocommerce-markdown` skill for its format
- `AGENTS.md` is the agent-facing source of truth; keep it terse and update it instead of duplicating rules into skill files
