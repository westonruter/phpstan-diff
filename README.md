# phpstan-diff

Run PHPStan and show **only the errors on lines you changed** relative to a base
branch (default `trunk`) — instead of being buried under every pre-existing
error in the codebase. Handy when you run PHPStan at a stricter `level:` locally
than the project's committed baseline.

There is no native "diff mode" in PHPStan itself — its baseline feature is a
snapshot, not a git diff. (The closest off-the-shelf alternative is
[`reviewdog`](https://github.com/reviewdog/reviewdog) with an `errorformat`, but
that's a Go binary.) This is a small self-contained pair of scripts:

| Script | What it is |
| --- | --- |
| `bin/phpstan-diff` | Bash wrapper — the thing you normally run. Runs PHPStan with `--error-format=json` and pipes it through the filter. |
| `bin/phpstan-diff-filter` | PHP filter — reads a PHPStan JSON report on stdin, keeps only messages on changed lines, prints a PHPStan-style table (or JSON), exits `1` if anything remains. |

## Install

```sh
git clone <this repo> ~/repos/phpstan-diff
ln -s ~/repos/phpstan-diff/bin/phpstan-diff ~/bin/phpstan-diff   # or add bin/ to $PATH
```

Requirements: `bash`, `git`, `php` (CLI), and PHPStan reachable from the project
directory (see *Locating PHPStan* below).

## Usage

Run it from the project root, just like `phpstan analyse`:

```sh
phpstan-diff                                  # analyse the whole configured project, show errors on lines changed vs trunk
phpstan-diff --changed                         # faster: only run PHPStan on the .php files that changed vs trunk
phpstan-diff -- src/wp-includes/rest-api.php   # restrict PHPStan (and the diff) to given paths
phpstan-diff --base=6.7-branch                 # diff against a different base ref
phpstan-diff --staged -- $(git diff --cached --name-only --diff-filter=ACMR -- '*.php')
phpstan-diff --format=json | jq '.files | keys'
```

Options:

- `--base=<ref>` — base ref to diff against. Default: `$PHPSTAN_DIFF_BASE` or `trunk`.
- `--staged` — filter to **staged** changes (`git diff --cached`) instead of the
  branch-vs-base diff. (PHPStan still analyses the working tree, not the staged blob.)
- `--changed` — when no paths are given, only run PHPStan on the `.php` files that
  differ from the base. Much faster for PR review; may miss errors that a change
  introduces in *other* files.
- `--format=table` (default) | `--format=json` — `json` emits the filtered PHPStan
  JSON document (same shape as `--error-format=json`, with non-changed files/messages removed).

What counts as a "changed line": every line that differs between
`git merge-base <base> HEAD` and your working tree — i.e. your branch commits
**plus** uncommitted edits. With `--staged`, it's the staged hunks instead.

Exit status: `1` if any errors remain after filtering, `0` if none, `2` on
internal errors (PHPStan didn't produce JSON, not a git repo, bad ref, …).

### Locating PHPStan

`bin/phpstan-diff` figures out how to run PHPStan, in this order:

1. `$PHPSTAN_DIFF_ANALYSE_CMD` if set — used verbatim, our extra args appended.
   E.g. `PHPSTAN_DIFF_ANALYSE_CMD='composer phpstan --'` or
   `PHPSTAN_DIFF_ANALYSE_CMD='vendor/bin/phpstan analyse -c phpstan.neon'`.
2. `composer phpstan --` if Composer reports a `phpstan` script.
3. `vendor/bin/phpstan analyse --memory-limit=2G` (honours a custom Composer `bin-dir`).
4. A globally-installed `phpstan analyse --memory-limit=2G`.
5. Otherwise it errors and tells you to install PHPStan or set `PHPSTAN_DIFF_ANALYSE_CMD`.

This means it picks up whatever config `composer phpstan` already uses — including a
local stricter `phpstan.neon` that overrides `phpstan.neon.dist`.

## Pre-commit hook

Drop this in `.git/hooks/pre-commit` (`chmod +x` it) to block commits that
introduce errors on the lines being committed. It only checks staged `.php`
files, never touches your working tree, and is bypassable with
`git commit --no-verify` (or disabled with `PHPSTAN_DIFF_PRECOMMIT_SKIP=1`):

```bash
#!/usr/bin/env bash
set -euo pipefail
[ -n "${PHPSTAN_DIFF_PRECOMMIT_SKIP:-}" ] && exit 0

if command -v phpstan-diff >/dev/null 2>&1; then
	phpstan_diff="$(command -v phpstan-diff)"
elif [ -x "$HOME/repos/phpstan-diff/bin/phpstan-diff" ]; then
	phpstan_diff="$HOME/repos/phpstan-diff/bin/phpstan-diff"
else
	echo "pre-commit: phpstan-diff not found — skipping PHPStan check." >&2
	exit 0
fi

files=()
while IFS= read -r f; do
	[ -n "$f" ] && files+=("$f")
done < <(git diff --cached --name-only --diff-filter=ACMR -- '*.php')
[ "${#files[@]}" -eq 0 ] && exit 0

# phpstan-diff analyses files on disk and maps results onto the staged hunks; if
# a staged file also has unstaged edits, that mapping is approximate — warn only.
git diff --quiet -- "${files[@]}" || \
	echo "pre-commit: note — some staged files have unstaged changes; line mapping is approximate." >&2

exec "$phpstan_diff" --staged -- "${files[@]}"
```

> **Don't `git stash` in the hook.** It's tempting to `git stash --keep-index`
> so PHPStan sees exactly the staged content, but with newly-added or
> partially-staged files the matching `git stash pop` can hit conflicts and
> leave your tree in a bad state. The hook above accepts a slightly approximate
> line mapping for partially-staged files instead — the common
> `git add <file> && git commit` case (working tree == index) is still exact.

## Using with Claude / during PR review

Tell Claude: "to check PHPStan, run `phpstan-diff --changed` and only act on what
it prints — those are the errors this branch introduced." (You may want to add
that to a project note / Claude memory so it's used automatically.)

## Known limitations

- Messages with no line, or a line outside every changed hunk (some file-level
  errors like "class not found"), are dropped.
- Truly untracked files aren't part of any diff, so PHPStan errors in them are
  dropped — `git add` them (or commit) to have them considered.
- Path quoting: paths with unusual characters in `git diff` headers may not be
  matched perfectly.
- The table output approximates `phpstan analyse`'s table; it isn't pixel-perfect
  and doesn't emit editor hyperlinks (but relative `path:line` strings are still
  clickable in most IDE terminals).

## AI disclosure

This tool (both `bin/` scripts and this README) was written by **Claude Code**
using the **Claude Opus 4.7 (1M context)** model (`claude-opus-4-7[1m]`), from the
prompt below, then reviewed and verified against a real repository.

## Details

<details>
<summary>Original prompt used to plan this tool</summary>

> I have a stricter phpstan.neon compared with phpstan.neon.dist. I always use the
> former with PhpStorm and it is automatically picked up with `composer phpstan`.
> However, the problem is that it reports many many errors for lines that aren't
> changed in the current branch compared with trunk. I want to create a command
> line tool called something like phpstan-diff which analyzes the project (or the
> supplied file paths) and then filters out anything that isn't among the modified
> lines in the current branch compared with `trunk`. And the base branch could also
> be configurable. Maybe this should be something which simply takes the output of a
> `composer phpstan -- --error-format=json` and then filters out the results (e.g.
> using jq). if there are errors, then the filter script can have an exit code of 1
> or else otherwise an exit code of 0. This filter script I can then use in a
> pre-commit hook as well as something which I can tell Claude about to use whenever
> I'm reviewing a PR. So it should be something that I can easily invoke from the
> command line. Maybe that means one filter command script and then another simple
> wrapper bash script around that filter command script. The wrapper is what I would
> normally invoke. The wrapper command can should format the errors in a way like
> `phpstan analyze` normally outputs.
>
> An example of something I've used for this in the past:
>
> ```
> composer phpstan -- --error-format=json -- src/wp-includes/rest-api.php 2>/dev/null \
>   | jq '.files["/Users/westonruter/repos/wordpress-develop/src/wp-includes/rest-api.php"].messages
>       | map(select(.line >= 2927 and .line <= 3031) | del(.ignorable))'
> ```
>
> Is there already a way to get PHPStan to only report errors for lines in a diff?
> Or do I need to create a new helper? If new helpers are needed, let's put them in
> the empty repo I just created at `~/repos/phpstan-diff/`

</details>

## License

[MIT](LICENSE) © Weston Ruter