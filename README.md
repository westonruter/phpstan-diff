# phpstan-diff

Run PHPStan and show **only the errors on lines you changed** relative to a base
branch (by default, the repository's default branch, e.g. `trunk` or `main`) —
instead of being buried under every pre-existing error in the codebase. Handy
when you run PHPStan at a stricter `level:` locally than the project's committed
baseline.

There is no native "diff mode" in PHPStan itself — its baseline feature is a
snapshot, not a git diff. (The closest off-the-shelf alternative is
[`reviewdog`](https://github.com/reviewdog/reviewdog) with an `errorformat`, but
that's a Go binary.) This is a small self-contained PHP tool:

| Script | What it is |
| --- | --- |
| `bin/phpstan-diff` | The thing you normally run. Works out the base, runs PHPStan with `--error-format=json` on the changed files, keeps only messages on changed lines, prints a PHPStan-style table (or JSON), and exits `1` if anything remains. |
| `bin/phpstan-diff-filter` | Just the filtering, for when you run PHPStan some other way: reads a PHPStan JSON report on stdin and diffs against the merge-base with `--base` (without looking at its remote-tracking counterparts). |
| `src/` | The code both of them use: the functions in `phpstan-diff.php` and a few enums, loaded by `load.php`. |

## Install

```sh
git clone <this repo> ~/repos/phpstan-diff
ln -s ~/repos/phpstan-diff/bin/phpstan-diff ~/bin/phpstan-diff   # or add bin/ to $PATH
```

Requirements: `git`, `php` (CLI, 8.2 or later), and PHPStan reachable from the project
directory (see *Locating PHPStan* below). It runs on macOS and Linux; Windows isn't
supported.

## Usage

Run it from the project root, just like `phpstan analyse`:

```sh
phpstan-diff                                  # analyze the .php files changed vs the default branch, show errors on the changed lines
phpstan-diff --whole-project                   # slower: analyze the whole configured project (still only reports changed lines)
phpstan-diff --exclude=tests                   # the changed files, minus anything under tests/
phpstan-diff --include=src                     # only changed files under src/
phpstan-diff -- src/wp-includes/rest-api.php   # restrict PHPStan (and the diff) to given paths
phpstan-diff --base=6.7-branch                 # diff against a different base ref
phpstan-diff --staged                          # like the default, but exclude unstaged edits (committed + staged vs the base)
phpstan-diff --staged --base=HEAD              # only the staged changes (pre-commit-hook scope)
phpstan-diff --no-ignore-whitespace            # also count lines whose only changes are in whitespace
phpstan-diff --format=json | jq '.files | keys'
```

Options:

- `--base=<ref>` — base ref to diff against. Default: `$PHPSTAN_DIFF_BASE`; else, in a
  GitHub Actions run for a pull request, the branch it targets (`$GITHUB_BASE_REF`);
  else the repository's default branch: the branch that `origin/HEAD` (or
  `upstream/HEAD`) points to, as `git clone` records it, or else the first of
  `develop` (git-flow's integration branch), `main`, `master` and `trunk` that exists.
  (If `origin/HEAD` is missing, `git remote set-head origin --auto` sets it.)
  When it names a local branch, its remote-tracking counterparts are considered
  too — see *Choosing the base* below.
- `--staged` — diff the **staged snapshot** (the git index) against the base instead
  of the working tree: errors on **committed + staged** changes, with unstaged edits
  ignored. Add `--base=HEAD` to narrow it to **staged changes only** (pre-commit-hook
  scope). (PHPStan still analyzes the working tree, not the staged blob.)
- `--no-ignore-whitespace` — count lines whose only changes are in whitespace as
  changed. By default (`--ignore-whitespace`) they don't count, as with `git diff -w`:
  re-indenting code (say, to wrap it in a new `if` block) doesn't bring its existing
  errors into the report, and files whose only changes are in whitespace aren't
  analyzed. But re-indented lines can have new errors all the same: code moved into a
  closure, say, loses the variables it used. So when errors on such lines aren't
  shown, a note says how many (and `--format=json` has the count, as
  `phpstan_diff.whitespace_only_errors`).
- `--whole-project` — when no paths are given, run PHPStan on the whole configured
  project instead of only the `.php` files that differ from the base (the default).
  Errors are still reported only on changed lines, so for most rules the output is
  identical — just slower. It matters for rules that collect data across files
  (e.g. dead-code detection), where whether a changed file has an error depends on
  how other files use it, and for changed files outside the config's `paths:`
  (explicit paths override them, so the default analyzes such files too; a
  whole-project run doesn't).
- `--changed` — deprecated no-op, accepted for backward compatibility: analyzing
  only the changed files is now the default.
- `--include=<glob>` — keep only files matching this **repo-relative** path or glob,
  intersected with the files that actually changed. Repeatable (a file is kept if it
  matches **any** `--include`). A bare directory matches everything under it
  (`--include=src` ⇒ `src/Foo.php`); globs work too (`--include='src/*'`, `*.php`),
  and `*` may span `/`.
- `--exclude=<glob>` — drop files matching this repo-relative path or glob
  (`--exclude=tests`). Repeatable; applied **after** `--include`, and an exclude wins
  over an include. Unless `--whole-project` is given, excluded files aren't handed
  to PHPStan at all, so the run is faster too.
- `--format=table` (default) | `--format=json` — `json` emits the filtered PHPStan
  JSON document (same shape as `--error-format=json`, with non-changed files/messages removed),
  plus a `phpstan_diff.base` object saying what the diff was taken against:
  `{"requested": "trunk", "used": [{"ref": "upstream/trunk", "commit": "<sha>"}], "staged": false}`.
  Each `commit` is what the diff was taken against: the ref's merge-base with
  `HEAD` (or the ref itself, if it shares no history with `HEAD`). When `used`
  lists several refs, a line counts as changed only if it changed relative to
  every one of them (see *Choosing the base*).

> [!NOTE]
> **`--include`/`--exclude` vs the positional `<paths>`.** The positional `<paths>`
> are PHPStan's **analysis targets** — handed to `phpstan analyse` verbatim to decide
> what it scans. `--include`/`--exclude` are **filters over the changed files**: they
> understand globs and, crucially, let you say "everything *except* tests"
> (`--exclude=tests`) — something a list of paths can't express. They're orthogonal
> and stack: `phpstan-diff --exclude=tests` derives the changed `.php` files, drops
> the test ones (so PHPStan never sees them), then line-filters the rest.

Changed files that the project's own PHPStan configuration excludes (via
`excludePaths`, e.g. bundled third-party libraries) are skipped by PHPStan as
usual, and a note lists them so you know they weren't checked. (PHPStan skips
them silently, so `phpstan-diff` works this out from `phpstan dump-parameters`,
run alongside the analysis.) If that leaves nothing to analyze — say, a commit
touching only an excluded file — PHPStan itself would fail with "No files found to
analyse"; `phpstan-diff` instead passes (exit `0`) with the note. (Likewise, when
no `.php` files changed at all, it passes without running PHPStan.)

```
 PHPStan's configuration (excludePaths) excludes 1 of the 3 files in scope, so it was not analyzed:
   src/wp-includes/class-IXR.php

 [OK] No errors on changed lines (base: trunk)
```

### Choosing the base

There's no need to pull the base branch, or to merge it into your feature branch,
before running `phpstan-diff`. The diff is always taken from the **merge-base** —
where your branch last took in the base — so a base branch that has moved on since
doesn't add its changes to yours.

What *would* add noise is a local base branch that is **behind**: if your branch
has merged `origin/trunk` (say, via GitHub's "Update branch" button) but your local
`trunk` hasn't been pulled, the merge-base with `trunk` lands too far back and the
upstream commits look like yours. So when `--base` names a local branch,
`phpstan-diff` also considers its upstream and `<branch>` on the `origin` and
`upstream` remotes (both, in a fork), and diffs against whichever your branch
merged **most recently**. Other remotes, such as a collaborator's fork, are left
out, since their branch may have merged yours. A branch that exists only on
remotes, as in a CI clone with `origin/trunk` but no local `trunk`, is handled
the same way.

Any of these that already contains `HEAD` (e.g. a `trunk` your branch has been
merged into) is skipped too, since it would hide every change, unless all of them
do, or it's the branch you have checked out (on `trunk` itself, the diff is
against `trunk`, i.e. your uncommitted changes). A note says when it picked a
remote-tracking ref over the local branch, and the summary line shows the ref
and the merge-base commit actually used:

```
 [OK] No errors on changed lines (base: upstream/trunk @ 1a2b3c4)
```

If your branch merged several of them and none contains the others (say,
`origin/trunk` with fork-only commits, and a newer `upstream/trunk`), a line
counts as changed only if it changed relative to **all** of their merge-bases, so
neither one's commits count as yours
(`base: upstream/trunk + origin/trunk @ 1a2b3c4 + 5d6e7f8`).

`phpstan-diff` never fetches: remote-tracking refs are only as fresh as your last
fetch. That's usually fresh enough, since `git pull` on your branch (to take in an
"Update branch" merge, say) fetches every branch of that remote, `origin/trunk`
included. But in a fork, if your branch merged `upstream/trunk` and you haven't
fetched `upstream` since, run `git fetch upstream` first; otherwise upstream's
newer commits look like yours.

In a **shallow clone** (e.g. `actions/checkout` with its default `fetch-depth: 1`)
there may not be enough history to find a merge-base. Then the diff is against
the base itself, so its newer changes count too, and a note says so. Fetch more
history (`fetch-depth: 0`, or `git fetch --unshallow`) to avoid that.

To use a local branch exactly as-is, spell out its full ref:
`--base=refs/heads/trunk`. Any base that isn't a local branch name (`origin/trunk`,
`HEAD`, a tag or commit) is also used as-is.

Merging the base into your branch still matters for what PHPStan *sees* — e.g.
when upstream changed the signature of a function your branch calls — but not for
which lines count as changed.

What counts as a "changed line": every line that differs between
`git merge-base <base> HEAD` and your working tree — i.e. your branch commits
**plus** staged and unstaged edits. With `--staged`, the comparison is against the
git index rather than the working tree, so unstaged edits drop out (committed +
staged only); adding `--base=HEAD` on top of that leaves just the staged changes.

Exit status: `1` if any errors remain after filtering, `0` if none, `2` on
internal errors (PHPStan didn't produce JSON, not a git repo, bad ref, …).

### Locating PHPStan

`bin/phpstan-diff` runs PHPStan from the project directory: the nearest one, from
the current directory up to the repository's top level, that has a PHPStan
configuration file (`phpstan.neon` etc.) or can run PHPStan (its `composer.json` has
a `phpstan` script, or its Composer bin-dir has `phpstan`); failing that, the top
level. The PHPStan binary may be in a directory further up. So it works the same
from a subdirectory, or from a package of a monorepo, with the package's own
configuration if it has one. It figures out how to run PHPStan, in this order:

1. `$PHPSTAN_DIFF_ANALYSE_CMD` if set — used verbatim, our extra args appended. It
   runs from the current directory, so relative paths in it mean what they would on
   the command line. If it runs a Composer script, that's split as below.
   E.g. `PHPSTAN_DIFF_ANALYSE_CMD='composer phpstan --'` or
   `PHPSTAN_DIFF_ANALYSE_CMD='vendor/bin/phpstan analyse -c phpstan.neon'`.
2. `composer phpstan --` if `composer.json` (or the file `$COMPOSER` names) has a
   `phpstan` script. If that script only lists other scripts (e.g.
   `["@phpstan:default", "@phpstan:strict"]`, one per configuration, possibly nested),
   each is run, one after another, and their reports are merged. (Run as one script,
   Composer would stop at the first that finds any error.)
3. `vendor/bin/phpstan analyse --memory-limit=2G` (honors a custom Composer `bin-dir`
   and `vendor-dir`).
4. A globally-installed `phpstan analyse --memory-limit=2G`.
5. Otherwise it errors and tells you to install PHPStan or set `PHPSTAN_DIFF_ANALYSE_CMD`.

PHPStan runs without a terminal: its stdin is `/dev/null`, as its output is captured.
So a `PHPSTAN_DIFF_ANALYSE_CMD` that needs one (e.g. `docker exec -it …`) won't work;
leave out `-t` (e.g. `docker exec app vendor/bin/phpstan analyse`).

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

# phpstan-diff analyzes files on disk and maps results onto the staged hunks; if
# a staged file also has unstaged edits, that mapping is approximate — warn only.
git diff --quiet -- "${files[@]}" || \
	echo "pre-commit: note — some staged files have unstaged changes; line mapping is approximate." >&2

# --base=HEAD scopes the diff to just the staged changes; without it, --staged
# would also report on changes committed earlier in the branch.
exec "$phpstan_diff" --staged --base=HEAD -- "${files[@]}"
```

> [!WARNING]
> **Don't `git stash` in the hook.** It's tempting to `git stash --keep-index`
> so PHPStan sees exactly the staged content, but with newly-added or
> partially-staged files the matching `git stash pop` can hit conflicts and
> leave your tree in a bad state. The hook above accepts a slightly approximate
> line mapping for partially-staged files instead — the common
> `git add <file> && git commit` case (working tree == index) is still exact.

## Using with Claude / during PR review

Tell Claude: "to check PHPStan, run `phpstan-diff` and only act on what
it prints — those are the errors this branch introduced." (You may want to add
that to a project note / Claude memory so it's used automatically.)

## Tests

```bash
tests/run-tests.sh                  # all tests
tests/run-tests.sh diverged_bases   # just the named ones
```

Each test builds throwaway git repositories (an upstream, a fork of it, and a
clone with a feature branch) in `$TMPDIR` and checks which lines `phpstan-diff`
reports, with `tests/fake-phpstan` standing in for PHPStan (it reports an error on
every line of each file it's given). The tests need `bash`, `git` and `php`, but not
PHPStan, and don't touch the network or your git configuration.

## Static analysis and code style

The tool's own PHP code is checked with PHPStan at level 10, with
[phpstan-strict-rules](https://github.com/phpstan/phpstan-strict-rules) (see
`phpstan.neon.dist`), and with PHP_CodeSniffer: PSR-12, but indented with tabs,
plus short array syntax and some of the
[Slevomat](https://github.com/slevomat/coding-standard) sniffs that PHPStan
itself uses (see `phpcs.xml.dist`):

```bash
composer install
composer phpstan
composer cs       # composer cs-fix to fix what it can
```

The tests, PHPStan and PHPCS all run in CI.

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

This tool (its scripts and this README) was written by **Claude Code**
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
