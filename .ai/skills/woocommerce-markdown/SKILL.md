---
name: woocommerce-markdown
description: Guidelines for creating and modifying markdown files in WooCommerce Fraud Protection. Use when writing documentation, README files, skill files, or any markdown content.
---

# WooCommerce Fraud Protection Markdown Guidelines

This skill provides guidance for creating and editing markdown files in this repository: `README.md`, `AGENTS.md`, the skill files under `.ai/skills/`, and the pull request template.

## Critical Rules

1. **Match the existing files** - there is no markdownlint configuration in this repository; consistency with the current documents is the standard
2. **Use UTF-8 encoding** - especially for directory trees and special characters
3. **Do not add markdown build or lint tooling** unless asked; see markdown-linting.md in the `woocommerce-dev-cycle` skill for the optional manual check
4. **Do not disclose private service behavior** in any documentation (see "Issues and pull requests" in `AGENTS.md`)

## Markdown Writing Guidelines

### Headings

```markdown
# Main Title (H1) - Only one per file

## Section (H2)

### Subsection (H3)

#### Minor Section (H4)
```

- Use ATX style (`#`) not underline style
- One H1 per file (usually the title)
- Maintain heading hierarchy (don't skip levels)
- Blank line before and after each heading

### Lists

**Unordered lists:**

```markdown
- Item one
- Item two
    - Nested item (4 spaces)
    - Another nested item
- Item three
```

**Ordered lists:**

```markdown
1. First item
2. Second item
3. Third item
```

**Important:**

- Use 4 spaces for nested list items
- Add a blank line before and after lists
- Use `-` for unordered lists (not `*` or `+`)

### Code Blocks

**Always specify the language:**

````markdown
```bash
npm run test:php:env -- --filter RuleStoreTest
```

```php
public function verify_session( string $session_id ): FraudDecision {
    // code here
}
```

```typescript
const rule = getRule( id );
```
````

**Common language identifiers:**

- `bash` - Shell commands
- `php` - PHP code
- `javascript` or `js` - JavaScript
- `typescript` or `ts` - TypeScript
- `json` - JSON data
- `sql` - SQL queries
- `text` - Plain output, changelog excerpts
- `markdown` or `md` - Markdown examples

**Code block rules:**

- Add a blank line before the opening fence
- Add a blank line after the closing fence
- Always specify a language (never use a bare ` ``` `)

### Inline Code

Use backticks for inline code:

```markdown
Use the `verify_session()` method to verify an attempt.
The `$session_id` parameter must be a string.
```

### Links

```markdown
[Link text](https://example.com)

[Companion file in the same skill](companion-file.md)

[Section in AGENTS.md](../../../AGENTS.md#safety-rules)
```

### Tables

```markdown
| Column 1 | Column 2 | Column 3 |
|----------|----------|----------|
| Value 1  | Value 2  | Value 3  |
| Value 4  | Value 5  | Value 6  |
```

- Use pipes (`|`) for column separators
- Header separator row required
- Alignment optional (`:---`, `:---:`, `---:`)

### Directory Trees

**Always use UTF-8 box-drawing characters:**

```markdown
src/
├── FraudProtection/
│   ├── Schemas/
│   │   └── FraudDecision.php
│   └── SessionVerifier.php
└── Internal/
    └── FraudProtectionPlugin/
        └── FraudProtectionController.php
```

**Never use:**

- ASCII art (`+--`, `|--`)
- Spaces or tabs for tree structure
- Control characters

### Emphasis

```markdown
**Bold text** for strong emphasis
*Italic text* for regular emphasis
```

Do not use bold text as a heading; use a real heading.

## Workflow for Editing Markdown

1. **Make your changes** to the markdown file
2. **Re-read the structure**: heading hierarchy, blank lines around lists and fences, a language on every fence, a single trailing newline
3. **Optionally run the manual check** described in markdown-linting.md in the `woocommerce-dev-cycle` skill
4. **Verify the encoding** with `file path/to/file.md` if the file contains box-drawing characters

## Special Cases

### AGENTS.md and CLAUDE.md

`CLAUDE.md` only includes `AGENTS.md`. `AGENTS.md` is the agent-facing source of truth for architecture, safety rules, logging, and process. Keep it terse and imperative, one rule per sentence, and update it rather than duplicating a rule into a skill file.

### Skill Files

Skills live under `.ai/skills/<name>/SKILL.md` with optional companion files. `.claude/skills` and `.codex/skills` are symlinks to `.ai/skills`; `.cursor/rules/` holds separate Cursor rule files. `SKILL.md` starts with YAML front matter (`name`, `description`). Link companion files with relative links and keep a table of contents in long companion files.

### README.md

`README.md` is the developer setup guide and the public API reference. The "Public API" section is part of the public contract; update it whenever the public API changes.

### changelog.txt

`changelog.txt` is plain text in the WooCommerce extension format, not markdown:

```text
*** WooCommerce Fraud Protection Changelog ***

YYYY-xx-xx - version 0.2.6
* Added - One sentence describing observable behavior.
* Fixed - One sentence describing observable behavior.

2026-09-16 - version 0.2.5
* Added - Checkout attempts list on the Fraud prevention settings page showing recent attempts and how each was handled, with filtering, sorting and search.
```

- A `YYYY-xx-xx` placeholder block at the top collects entries for the next release; the release pull request replaces the date
- Categories are `Added`, `Updated`, `Fixed`, and `Dev`
- Entries describe observable plugin behavior for merchants or developers; tests, CI, documentation, and internal refactoring get no entry (see "Issues and pull requests" in `AGENTS.md`)

## Troubleshooting

### File Shows as "data" Instead of Text

**Problem:** File is corrupted with control characters

**Fix:**

```bash
tr -d '\000-\037' < file.md > file.clean.md && mv file.clean.md file.md
file file.md  # Verify shows "UTF-8 text"
```

## Notes

- Consistency with the existing files matters more than any particular lint rule
- UTF-8 encoding is critical for special characters
- See the `woocommerce-dev-cycle` skill for the optional markdownlint commands
