#!/usr/bin/env bash
#
# Tests for phpstan-diff. Each test builds throwaway git repositories (an
# "upstream" repo, a fork of it, and a working clone with a feature branch) and
# checks which lines phpstan-diff reports, with tests/fake-phpstan standing in
# for PHPStan: it reports an error on every line of each file it's given.
#
# Usage: tests/run-tests.sh [<test-name>...]
#
# Set PHPSTAN_DIFF and PHPSTAN_DIFF_FILTER to test other copies of the scripts,
# such as the PHARs that tools/build-phar builds.
#
# Exit status: 0 if every test passes, 1 otherwise.

# shellcheck disable=SC2016  # The PHP snippets are single-quoted on purpose.

set -euo pipefail

TESTS_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd -P)"
PHPSTAN_DIFF="${PHPSTAN_DIFF:-$TESTS_DIR/../bin/phpstan-diff}"
FILTER="${PHPSTAN_DIFF_FILTER:-$TESTS_DIR/../bin/phpstan-diff-filter}"
# The tests run them from other directories, so a relative override is made
# absolute here.
[[ "$PHPSTAN_DIFF" == /* ]] || PHPSTAN_DIFF="$PWD/$PHPSTAN_DIFF"
[[ "$FILTER" == /* ]] || FILTER="$PWD/$FILTER"
LIBRARY="$TESTS_DIR/../src/load.php"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/phpstan-diff-tests.XXXXXX")"
trap 'rm -rf "$scratch"' EXIT

# Keep the user's git configuration (commit signing, hooks, aliases, …) and
# environment out of the tests.
unset GIT_CONFIG_COUNT GIT_CONFIG_PARAMETERS GIT_SSH GIT_SSH_COMMAND GIT_DIR GIT_WORK_TREE PHPSTAN_DIFF_BASE GITHUB_BASE_REF PHPSTAN_DIFF_LEVEL
export GIT_CONFIG_NOSYSTEM=1
export GIT_CONFIG_GLOBAL="$scratch/gitconfig"
cat > "$GIT_CONFIG_GLOBAL" <<'EOF'
[user]
	name = phpstan-diff tests
	email = tests@example.com
[commit]
	gpgsign = false
[tag]
	gpgsign = false
[init]
	defaultBranch = trunk
[advice]
	detachedHead = false
EOF
export PHPSTAN_DIFF_ANALYSE_CMD="$TESTS_DIR/fake-phpstan"
export NO_COLOR=1 COLUMNS=200

# --- Helpers -----------------------------------------------------------------

# Write $2 to file $1 (with a trailing newline per line given).
put() {
	local file="$1"; shift
	printf '%s\n' "$@" > "$file"
}

commit_all() {
	git add -A
	git commit -q -m "$1"
}

# Build the repositories and cd into the working clone, on branch "feature":
#
#   upstream   trunk: b.php (lines "// one" … "// five"), then u.php is added.
#   fork.git   a fork of upstream's first commit, then "// five FORK" in b.php
#              and f.php are added (fork-only commits).
#   work       cloned from upstream before u.php; remotes "upstream" (which
#              local trunk tracks) and "origin" (the fork, fetched before the
#              fork-only commit). Branch "feature" changes line 4 of b.php to
#              "// three MINE" and adds mine.php.
#
# So the feature branch's own changes are always b.php:4 and mine.php:1.
setup_repos() {
	local repos="$scratch/repos"
	rm -rf "$repos"
	mkdir -p "$repos"
	cd "$repos"
	git init -q upstream
	(
		cd upstream
		put b.php '<?php' '// one' '// two' '// three' '// four' '// five'
		commit_all 'Add b.php'
	)
	git clone -q --bare upstream fork.git
	git clone -q upstream work
	(
		cd work
		git remote rename origin upstream
		git remote add origin "$repos/fork.git"
		git fetch -q origin
		git branch -q --set-upstream-to=upstream/trunk trunk
		git checkout -q -b feature
		put b.php '<?php' '// one' '// two' '// three MINE' '// four' '// five'
		put mine.php '<?php // mine'
		commit_all 'Feature'
	)
	(
		cd upstream
		put u.php '<?php // upstream'
		commit_all 'Upstream change'
	)
	git clone -q fork.git fork-work
	(
		cd fork-work
		put b.php '<?php' '// one' '// two' '// three' '// four' '// five FORK'
		put f.php '<?php // fork'
		commit_all 'Fork-only change'
		git push -q origin trunk
	)
	cd "$repos/work"
}

# Run phpstan-diff --format=json with the given arguments, in the current
# directory. Sets $status, $stderr, $errors (the sorted "<file>:<line>" of each
# error kept, space-separated) and $used (the refs the diff was taken from,
# joined with " + ").
pd() {
	local json
	status=0
	json="$("$PHPSTAN_DIFF" --format=json "$@" 2>"$scratch/stderr")" || status=$?
	summarize "$json"
}

# Like pd(), but run phpstan-diff-filter --format=json with the given arguments
# on what tests/fake-phpstan reports for the .php files in the current directory.
pd_filter() {
	local json
	status=0
	# (Like PHPStan, fake-phpstan exits 1 when it reports errors.)
	json="$({ "$TESTS_DIR/fake-phpstan" -- *.php || true; } \
		| "$FILTER" --format=json "$@" 2>"$scratch/stderr")" || status=$?
	summarize "$json"
}

# Set $stderr, $errors and $used (see pd()) from the stderr file and JSON report $1.
summarize() {
	local summary
	stderr="$(cat "$scratch/stderr")"
	errors=""
	used=""
	[ "${1:0:1}" = "{" ] || return 0
	summary="$(printf '%s' "$1" | php -r '
		$report = json_decode(stream_get_contents(STDIN), true);
		$errors = array();
		foreach ($report["files"] as $file) {
			foreach ($file["messages"] as $message) {
				$errors[] = $message["message"];
			}
		}
		sort($errors);
		echo implode(" ", $errors), "\n";
		echo implode(" + ", array_column($report["phpstan_diff"]["base"]["used"], "ref")), "\n";
	')"
	errors="${summary%%$'\n'*}"
	used="${summary#*$'\n'}"
}

test_failed=0
stderr=""   # Set by pd(), for expect() to show on failure.

# expect <description> <actual> <expected>
expect() {
	if [ "$2" != "$3" ]; then
		printf '    %s: expected "%s", got "%s"\n' "$1" "$3" "$2"
		if [ -n "$stderr" ]; then
			printf '    stderr of the last phpstan-diff run: %s\n' "$stderr"
		fi
		test_failed=1
	fi
	return 0
}

# expect_contains <description> <haystack> <needle>
expect_contains() {
	if [[ "$2" != *"$3"* ]]; then
		printf '    %s: expected to contain "%s", got "%s"\n' "$1" "$3" "$2"
		test_failed=1
	fi
	return 0
}

# expect_not_contains <description> <haystack> <needle>
expect_not_contains() {
	if [[ "$2" == *"$3"* ]]; then
		printf '    %s: expected not to contain "%s", got "%s"\n' "$1" "$3" "$2"
		test_failed=1
	fi
	return 0
}

own_errors='b.php:4 mine.php:1'

# --- Tests -------------------------------------------------------------------

test_base_not_moved() {
	setup_repos
	pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	expect used "$used" trunk
}

# --level (else $PHPSTAN_DIFF_LEVEL) overrides the configuration's rule level, and
# the baseline entries that then don't fit (unmatched, or a count that's off)
# aren't reported.
test_level() {
	setup_repos
	# Without the configuration's level (which only `phpstan dump-parameters` gives,
	# and the fake can't be found to run that here), any level but max counts as
	# lower than it: then no error about an ignore that doesn't fit is shown.
	pd --level 3
	expect "lower status" "$status" 1
	expect "lower errors" "$errors" 'b.php:4@3 mine.php:1@3'
	# The two on mine.php:1 (b.php:1 didn't change), and the two not tied to a file.
	expect_contains "lower note" "$stderr" "4 errors about ignores"
	expect_contains "lower note level" "$stderr" "don't fit level 3 aren't shown"
	expect_contains "lower note not tied" "$stderr" "2 of them aren't tied to a file"
	# With no other errors, those don't fail the run.
	FAKE_PHPSTAN_FILES=none.php pd --level=3
	expect "only ignore errors status" "$status" 0
	# Levels are cumulative, so at max an ignore that doesn't fit is stale (here, a
	# phpstan-ignore comment that ignores nothing, and unmatched entries), and it's
	# shown. Only a count that's off isn't.
	pd --level=max
	expect "max status" "$status" 1
	expect "max errors" "$errors" 'No error to ignore is reported on line 1. b.php:4@max mine.php:1@max'
	expect_contains "max note" "$stderr" "1 error about a baseline count (ignore.count) that's off at level max isn't shown"
	FAKE_PHPSTAN_FILES=none.php pd --level=max
	expect "max unmatched entries status" "$status" 1

	# Where `phpstan dump-parameters` gives the configuration's level, a level at
	# least as strict counts the same as max, and a lower one doesn't.
	mkdir -p "$scratch/level-bin"
	ln -sf "$TESTS_DIR/fake-phpstan" "$scratch/level-bin/phpstan"
	FAKE_PHPSTAN_CONFIG_LEVEL=5 PHPSTAN_DIFF_ANALYSE_CMD="$scratch/level-bin/phpstan analyse" pd --level=5
	expect "same level errors" "$errors" 'No error to ignore is reported on line 1. b.php:4@5 mine.php:1@5'
	FAKE_PHPSTAN_CONFIG_LEVEL=5 PHPSTAN_DIFF_ANALYSE_CMD="$scratch/level-bin/phpstan analyse" pd --level=4
	expect "known lower errors" "$errors" 'b.php:4@4 mine.php:1@4'
	FAKE_PHPSTAN_CONFIG_LEVEL=max PHPSTAN_DIFF_ANALYSE_CMD="$scratch/level-bin/phpstan analyse" pd --level=10
	expect "below max errors" "$errors" 'b.php:4@10 mine.php:1@10'

	PHPSTAN_DIFF_LEVEL=5 pd
	expect "PHPSTAN_DIFF_LEVEL errors" "$errors" 'b.php:4@5 mine.php:1@5'
	PHPSTAN_DIFF_LEVEL=5 pd --level=10
	expect "--level over PHPSTAN_DIFF_LEVEL errors" "$errors" 'b.php:4@10 mine.php:1@10'
	# An empty --level is the configuration's, whatever $PHPSTAN_DIFF_LEVEL says.
	PHPSTAN_DIFF_LEVEL=5 pd --level=
	expect "empty --level errors" "$errors" "$own_errors"
	expect_not_contains "empty --level note" "$stderr" "about ignores"
	# With the level in $PHPSTAN_DIFF_ANALYSE_CMD too, ours comes last, so it wins.
	PHPSTAN_DIFF_ANALYSE_CMD="$TESTS_DIR/fake-phpstan analyse --level=1" pd --level=3
	expect "--level after the command's errors" "$errors" 'b.php:4@3 mine.php:1@3'
	# Without an override, ignores that don't fit are left for the configuration to settle.
	pd
	expect "no level errors" "$errors" "$own_errors"

	# phpstan-diff-filter's --level says what level PHPStan ran at.
	local json
	json="$({ "$TESTS_DIR/fake-phpstan" --level=3 -- *.php || true; } \
		| "$FILTER" --format=json --level=3 2>"$scratch/stderr")" || true
	summarize "$json"
	expect "filter --level errors" "$errors" 'b.php:4@3 mine.php:1@3'
	expect_contains "filter --level note" "$stderr" "4 errors about ignores"

	pd --level=11
	expect "bad --level status" "$status" 2
	expect_contains "bad --level stderr" "$stderr" "--level must be a rule level from 0 to 10, or 'max' (got '11')"
	PHPSTAN_DIFF_LEVEL=strict pd
	expect "bad PHPSTAN_DIFF_LEVEL status" "$status" 2
	expect_contains "bad PHPSTAN_DIFF_LEVEL stderr" "$stderr" "PHPSTAN_DIFF_LEVEL must be a rule level"
}
test_include_exclude() {
	setup_repos
	pd --exclude=mine.php
	expect errors "$errors" 'b.php:4'
	pd --include=mine.php
	expect errors "$errors" 'mine.php:1'
}

test_staged_against_head() {
	setup_repos
	printf '// staged\n' >> mine.php
	git add mine.php
	printf '// unstaged\n' >> b.php
	pd --staged --base=HEAD
	expect errors "$errors" 'mine.php:2'
}

test_no_changed_files_passes() {
	setup_repos
	pd --base=HEAD
	expect status "$status" 0
	expect_contains stderr "$stderr" 'No changed .php files'
}

test_invalid_base() {
	setup_repos
	pd --base=no-such-branch
	expect status "$status" 2
	expect_contains stderr "$stderr" 'not a valid commit or branch'
}

# The filter hands `git diff` only a base that git resolved to a commit, so one
# that looks like an option isn't taken as one.
test_filter_invalid_base() {
	setup_repos
	pd_filter --base=no-such-branch
	expect status "$status" 2
	expect_contains stderr "$stderr" 'not a valid commit'
	pd_filter --base=--output=leak.txt
	expect 'option as --base status' "$status" 2
	expect_contains 'option as --base stderr' "$stderr" 'not a valid commit'
	PHPSTAN_DIFF_BASE=--output=leak.txt pd_filter
	expect 'option as $PHPSTAN_DIFF_BASE status' "$status" 2
	GITHUB_BASE_REF=--output=leak.txt pd_filter
	expect 'option as $GITHUB_BASE_REF status' "$status" 2
	expect 'file written by git diff' "$(ls leak.txt 2>/dev/null || true)" ''
	pd --base=--output=leak.txt
	expect 'phpstan-diff, option as --base status' "$status" 2
	expect 'phpstan-diff, file written by git diff' "$(ls leak.txt 2>/dev/null || true)" ''
}

# PHPStan reports an error in a trait under the trait's file followed by the class
# that uses it; it's still on a changed line of that file.
test_trait_errors() {
	setup_repos
	export FAKE_PHPSTAN_TRAITS=mine.php
	pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	pd --exclude=mine.php
	expect 'excluded trait errors' "$errors" 'b.php:4'
	pd --include=mine.php
	expect 'included trait errors' "$errors" 'mine.php:1'
	pd_filter
	expect 'filter errors' "$errors" "$own_errors"
	status=0
	output="$("$PHPSTAN_DIFF" 2>&1)" || status=$?
	expect_contains 'table output' "$output" 'mine.php (in context of class FakeClass)'
}

# PHPStan analyzes a trait only along with a class that uses it, so the files that
# mention a changed trait are handed to PHPStan too (unless --exclude drops them).
test_trait_users() {
	local log="$scratch/fake-phpstan.log"
	setup_repos
	mkdir tests
	put t.php '<?php' 'trait Shared {}'
	put uses.php '<?php' 'class Uses { use Shared; }'
	put tests/uses.php '<?php' 'class UsesToo { use \Shared; }'
	put other.php '<?php' 'class SharedThing {}'
	commit_all 'Add a trait'
	put t.php '<?php' 'trait Shared {' '}'
	rm -f "$log"
	FAKE_PHPSTAN_LOG="$log" pd --base=HEAD
	expect errors "$errors" 't.php:2 t.php:3'
	expect 'files analyzed' "$(sed 's|.*/work/||' "$log" | sort | xargs echo)" 't.php tests/uses.php uses.php'
	rm -f "$log"
	FAKE_PHPSTAN_LOG="$log" pd --base=HEAD --exclude=tests
	expect 'files analyzed with --exclude' "$(sed 's|.*/work/||' "$log" | sort | xargs echo)" 't.php uses.php'
	# Without a trait among the changed files, only they are analyzed.
	git checkout -q t.php
	put other.php '<?php' 'class SharedThing {' '}'
	rm -f "$log"
	FAKE_PHPSTAN_LOG="$log" pd --base=HEAD
	expect 'files analyzed without a trait' "$(sed 's|.*/work/||' "$log" | sort | xargs echo)" 'other.php'
}

# The changed files that are analyzed are those with an extension that PHPStan's
# configuration has it analyze (fileExtensions): .php ones, by default.
test_file_extensions() {
	local log="$scratch/fake-phpstan.log"
	setup_repos
	mkdir -p "$scratch/extensions-bin"
	ln -sf "$TESTS_DIR/fake-phpstan" "$scratch/extensions-bin/phpstan"
	export PHPSTAN_DIFF_ANALYSE_CMD="$scratch/extensions-bin/phpstan analyse"
	put x.inc '<?php // inc'
	put notes.txt 'Notes'
	put upper.PHP '<?php // upper'
	commit_all 'Add files with other extensions'
	pd
	expect 'default errors' "$errors" "$own_errors"
	export FAKE_PHPSTAN_EXTENSIONS=php,inc
	rm -f "$log"
	FAKE_PHPSTAN_LOG="$log" pd
	expect 'php,inc errors' "$errors" "$own_errors x.inc:1"
	expect 'php,inc files analyzed' "$(sed 's|.*/work/||' "$log" | sort | xargs echo)" 'b.php mine.php x.inc'
	# (The configuration is only asked for when a file other than a .php one changed.)
	put notes.txt 'More notes'
	pd --base=HEAD
	expect_contains 'php,inc note' "$stderr" 'No changed .php/.inc files in scope'
	export FAKE_PHPSTAN_EXTENSIONS=inc
	pd
	expect 'inc errors' "$errors" 'x.inc:1'
	# Each of several configurations is handed the files with its own extensions.
	if ! command -v composer >/dev/null; then
		echo '    (the rest is skipped: composer is not installed)'
		return 0
	fi
	export COMPOSER_HOME="$scratch/composer-home"
	unset FAKE_PHPSTAN_EXTENSIONS
	put inc.neon 'extensions:inc'
	cat > composer.json <<-EOF
		{
			"scripts": {
				"phpstan": ["@phpstan:php", "@phpstan:inc"],
				"phpstan:php": "$scratch/extensions-bin/phpstan analyse",
				"phpstan:inc": "$scratch/extensions-bin/phpstan analyse -c inc.neon"
			}
		}
	EOF
	put .git/info/exclude /composer.json /inc.neon
	rm -f "$log"
	PHPSTAN_DIFF_ANALYSE_CMD='' FAKE_PHPSTAN_LOG="$log" pd
	expect 'per-configuration errors' "$errors" "$own_errors x.inc:1"
	expect 'per-configuration files analyzed' "$(sed 's|.*/work/||' "$log" | sort | xargs echo)" 'b.php mine.php x.inc'
}

test_version() {
	local output pattern
	pattern='^phpstan-diff [0-9a-z][^[:space:]]*$'
	output="$("$PHPSTAN_DIFF" --version)"
	[[ "$output" =~ $pattern ]] || expect '--version' "$output" 'phpstan-diff <version>'
	pattern='^phpstan-diff-filter [0-9a-z][^[:space:]]*$'
	output="$("$FILTER" -V < /dev/null)"
	[[ "$output" =~ $pattern ]] || expect 'filter -V' "$output" 'phpstan-diff-filter <version>'
}

# With the real PHPStan rather than tests/fake-phpstan: the one that Composer
# installed for this repository, or $PHPSTAN_DIFF_TESTS_PHPSTAN. Skipped if there's
# none, unless $PHPSTAN_DIFF_TESTS_REQUIRE_PHPSTAN is set (as in CI).
test_real_phpstan() {
	local phpstan="${PHPSTAN_DIFF_TESTS_PHPSTAN:-$TESTS_DIR/../vendor/bin/phpstan}" json
	if [ ! -x "$phpstan" ]; then
		if [ -n "${PHPSTAN_DIFF_TESTS_REQUIRE_PHPSTAN:-}" ]; then
			expect 'PHPStan' "$phpstan (not found)" 'an executable'
		fi
		return 0
	fi
	# Found as a project's own PHPStan is: in its Composer bin-dir.
	unset PHPSTAN_DIFF_ANALYSE_CMD
	rm -rf "$scratch/real"
	mkdir -p "$scratch/real/src/lib" "$scratch/real/vendor/bin"
	cd "$scratch/real"
	git init -q
	printf '#!/bin/sh\nexec "%s" "$@"\n' "$phpstan" > vendor/bin/phpstan
	chmod +x vendor/bin/phpstan
	put .gitignore '/vendor/' '/tmp/'
	printf 'parameters:\n\tlevel: 5\n\tpaths:\n\t\t- src\n\texcludePaths:\n\t\t- src/lib\n\ttmpDir: tmp\n' > phpstan.neon
	printf '\tfileExtensions:\n\t\t- php\n\t\t- inc\n' >> phpstan.neon
	put src/T.php '<?php' 'trait T {' '	public function a(): int {' '		return 1;' '	}' '}'
	put src/C.php '<?php' 'class C {' '	use T;' '}'
	put src/a.php '<?php' 'function old(): int {' '	return strlen(1);' '}'
	put src/lib/x.php '<?php' 'function lib(): void {}'
	commit_all 'Base'
	git checkout -q -b feature
	put src/T.php '<?php' 'trait T {' '	public function a(): int {' '		return $this->nope();' '	}' '}'
	put src/a.php '<?php' 'function old(): int {' '	return strlen(1);' '}' 'function added(): int {' '	return strlen(2);' '}'
	put src/lib/x.php '<?php' 'function lib(): void { nope(); }'
	put src/b.inc '<?php' 'function inc(): int {' '	return strrev(3);' '}'
	git add src/b.inc

	# The error in the changed trait (which is analyzed along with the class that
	# uses it), and the new one of the two in a.php; the file under excludePaths
	# is named in a note.
	pd --base=trunk
	expect status "$status" 1
	expect_contains 'trait error' "$errors" 'Call to an undefined method C::nope().'
	expect 'errors in a.php' "$(grep -o 'strlen' <<< "$errors" | wc -l | tr -d ' ')" 1
	expect_contains 'error in a file with another extension' "$errors" 'strrev'
	expect_contains 'excluded note' "$stderr" 'excludes 1 of the 4 files in scope'
	expect_contains 'excluded file' "$stderr" 'src/lib/x.php'

	# At level 0, the argument's type isn't checked.
	pd --base=trunk --level=0
	expect 'level 0 status' "$status" 1
	expect_contains 'level 0 trait error' "$errors" 'C::nope()'
	expect_not_contains 'level 0 errors' "$errors" 'strlen'

	pd --base=trunk --whole-project
	expect_contains 'whole-project trait error' "$errors" 'C::nope()'
	expect 'whole-project errors in a.php' "$(grep -o 'strlen' <<< "$errors" | wc -l | tr -d ' ')" 1

	# The filter, on the report of a PHPStan run by hand.
	status=0
	json="$({ vendor/bin/phpstan analyse --error-format=json --no-progress 2>/dev/null || true; } \
		| "$FILTER" --format=json --base=trunk 2>"$scratch/stderr")" || status=$?
	summarize "$json"
	expect 'filter status' "$status" 1
	expect_contains 'filter trait error' "$errors" 'C::nope()'
	expect 'filter errors in a.php' "$(grep -o 'strlen' <<< "$errors" | wc -l | tr -d ' ')" 1
}

# The user's git configuration and the repository's attributes don't change how
# the diff is read: an external diff program, paths relative to the current
# directory, and files marked as binary.
test_git_diff_configuration() {
	setup_repos
	mkdir sub
	put sub/s.php '<?php // sub'
	commit_all 'Add sub/s.php'
	cd sub
	git config diff.relative true
	pd
	expect 'diff.relative status' "$status" 1
	expect 'diff.relative errors' "$errors" "$own_errors s.php:1"
	pd_filter
	expect 'diff.relative filter errors' "$errors" 's.php:1'
	git config --unset diff.relative
	git config diff.external true
	pd
	expect 'diff.external errors' "$errors" "$own_errors s.php:1"
	GIT_EXTERNAL_DIFF=true pd
	expect 'GIT_EXTERNAL_DIFF errors' "$errors" "$own_errors s.php:1"
	git config --unset diff.external
	put ../.gitattributes '*.php -diff'
	pd
	expect 'binary attribute errors' "$errors" "$own_errors s.php:1"
	put ../.gitattributes '*.php diff=fake'
	git config diff.fake.textconv 'printf "<?php\n" #'
	pd
	expect 'textconv errors' "$errors" "$own_errors s.php:1"
}

# The branch merged upstream/trunk, but local trunk wasn't pulled.
test_stale_local_base() {
	setup_repos
	git fetch -q upstream
	git merge -q --no-edit upstream/trunk
	pd
	expect errors "$errors" "$own_errors"
	expect used "$used" upstream/trunk
	expect_contains stderr "$stderr" 'that the local trunk lacks'
}

test_local_base_as_is() {
	setup_repos
	git fetch -q upstream
	git merge -q --no-edit upstream/trunk
	pd --base=refs/heads/trunk
	expect errors "$errors" "$own_errors u.php:1"
	expect used "$used" refs/heads/trunk
}

# The branch merged both the fork (with a fork-only commit) and a newer
# upstream: neither one's commits are the branch's.
test_diverged_bases() {
	setup_repos
	git fetch -q upstream
	git fetch -q origin
	git merge -q --no-edit origin/trunk
	git merge -q --no-edit upstream/trunk
	pd
	expect errors "$errors" "$own_errors"
	expect used "$used" 'upstream/trunk + origin/trunk'
	expect_contains stderr "$stderr" 'histories have diverged'
}

# A remote other than origin/upstream (e.g. a collaborator's fork that merged
# this branch) must not count as a base.
test_other_remotes_ignored() {
	setup_repos
	git remote add collaborator "$scratch/repos/upstream"
	git update-ref refs/remotes/collaborator/trunk HEAD
	pd
	expect errors "$errors" "$own_errors"
	expect used "$used" trunk
}

# origin/trunk has merged this branch; using it would hide every change.
test_remote_containing_head_skipped() {
	setup_repos
	git update-ref refs/remotes/origin/trunk HEAD
	pd
	expect errors "$errors" "$own_errors"
	expect used "$used" trunk
}

# Local trunk has merged this branch; using it would hide every change.
test_local_base_containing_head_skipped() {
	setup_repos
	git branch -q -f trunk feature
	pd
	expect errors "$errors" "$own_errors"
	expect used "$used" upstream/trunk
	expect_contains stderr "$stderr" 'already contains this branch'
}

# Every candidate contains HEAD: nothing changed.
test_all_bases_containing_head() {
	setup_repos
	git branch -q -f trunk feature
	git update-ref refs/remotes/upstream/trunk HEAD
	git update-ref refs/remotes/origin/trunk HEAD
	pd
	expect status "$status" 0
	expect errors "$errors" ''
}

# On trunk itself, with an unpushed commit: only uncommitted changes count.
test_checked_out_base() {
	setup_repos
	git checkout -q trunk
	put t1.php '<?php // unpushed'
	commit_all 'Unpushed'
	put t2.php '<?php // uncommitted'
	git add t2.php
	pd
	expect errors "$errors" 't2.php:1'
	expect used "$used" trunk
}

# A CI clone: origin/trunk and upstream/trunk (here at the same commit, so the
# first, origin/trunk, is named), but no local trunk.
test_no_local_base() {
	setup_repos
	git branch -q -D trunk
	pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	expect used "$used" origin/trunk
	expect_not_contains stderr "$stderr" 'local trunk'
}

test_unrelated_local_base() {
	setup_repos
	git checkout -q --orphan orphan
	git rm -rqf .
	put z.php '<?php // unrelated'
	commit_all 'Unrelated'
	git branch -q -f trunk orphan
	git checkout -q feature
	pd
	expect errors "$errors" "$own_errors"
	expect used "$used" upstream/trunk
	expect_contains stderr "$stderr" 'shares no history'
}

# A tag named like the base branch mustn't be used in its place.
test_tag_shadowing_base() {
	setup_repos
	git tag trunk feature
	pd
	expect errors "$errors" "$own_errors"
	expect used "$used" trunk
}

test_base_with_spaces() {
	setup_repos
	pd --base='trunk@{1 week ago}'
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	expect used "$used" 'trunk@{1 week ago}'
}

# Diffs against diverged merge-bases may place the same hunk differently.
test_combine_ranges() {
	local result
	result="$(php -r '
		require $argv[1];
		$cases = array(
			// A slid hunk (same added text): the union.
			array( array( array( 10, 14, "x" ) ), array( array( 11, 15, "x" ) ), array( array( 10, 15 ) ) ),
			// Different changes that overlap: the intersection.
			array( array( array( 4, 5, "a" ) ), array( array( 3, 4, "b" ) ), array( array( 4, 4 ) ) ),
			array( array( array( 10, 14, "a" ) ), array( array( 12, 15, "b" ) ), array( array( 12, 14 ) ) ),
			array( array( array( 4, 6, "a" ) ), array( array( 4, 4, "b" ) ), array( array( 4, 4 ) ) ),
			array( array( array( 10, 20, "a" ) ), array( array( 5, 12, "b" ), array( 18, 25, "c" ) ), array( array( 10, 12 ), array( 18, 20 ) ) ),
			// A hunk with no counterpart in the other diff is dropped.
			array( array( array( 4, 4, "a" ), array( 20, 22, "c" ) ), array( array( 4, 4, "a" ) ), array( array( 4, 4 ) ) ),
			array( array( array( 1, 2, "a" ) ), array( array( 5, 6, "b" ) ), array() ),
		);
		foreach ($cases as $index => $case) {
			$got = array_map(static fn($range) => array( $range[0], $range[1] ), \WestonRuter\PHPStanDiff\combine_ranges($case[0], $case[1]));
			if ($got !== $case[2]) {
				echo "case $index: got ", json_encode($got), "; ";
			}
		}
	' "$LIBRARY")"
	expect combine_ranges "$result" ''
}

# Upstream and the fork each changed a line next to the branch's own change,
# so the hunks from the two diffs overlap without being the same change.
test_diverged_bases_adjacent_changes() {
	setup_repos
	(
		cd "$scratch/repos/upstream"
		put b.php '<?php' '// one' '// two UP' '// three' '// four' '// five'
		commit_all 'Upstream change next to the feature'
	)
	(
		cd "$scratch/repos/fork-work"
		put b.php '<?php' '// one' '// two' '// three' '// four FORK' '// five FORK'
		commit_all 'Fork-only change next to the feature'
		git push -q origin trunk
	)
	git fetch -q upstream
	git fetch -q origin
	# (Adjacent changes conflict, so resolve each merge by hand.)
	git merge -q --no-edit origin/trunk >/dev/null 2>&1 || true
	put b.php '<?php' '// one' '// two' '// three MINE' '// four FORK' '// five FORK'
	git add -A
	git commit -q --no-edit
	git merge -q --no-edit upstream/trunk >/dev/null 2>&1 || true
	put b.php '<?php' '// one' '// two UP' '// three MINE' '// four FORK' '// five FORK'
	git add -A
	git commit -q --no-edit
	pd
	expect errors "$errors" "$own_errors"
	expect used "$used" 'upstream/trunk + origin/trunk'
}

# With too little history for a merge-base, the diff is against the base's tip,
# and a note says why.
test_shallow_clone() {
	setup_repos
	git clone -q --depth 1 --no-single-branch "file://$scratch/repos/work" "$scratch/repos/shallow"
	cd "$scratch/repos/shallow"
	pd
	expect status "$status" 1
	expect used "$used" origin/trunk
	expect_contains stderr "$stderr" 'This is a shallow clone'
}

# From a subdirectory, changed files across the whole repository count, and
# --include/--exclude patterns stay relative to the top level.
test_from_subdirectory() {
	setup_repos
	mkdir sub
	put sub/s.php '<?php // sub'
	commit_all 'Add sub/s.php'
	cd sub
	pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors s.php:1"
	pd --include=sub
	expect errors "$errors" 's.php:1'
	pd --exclude=sub
	expect errors "$errors" "$own_errors"
}

test_table_format() {
	local output
	setup_repos
	status=0
	output="$("$PHPSTAN_DIFF" 2>&1)" || status=$?
	expect status "$status" 1
	expect_contains output "$output" '[ERROR] Found 2 errors on changed lines (base: trunk @ '
	expect_contains output "$output" 'mine.php:1'
	status=0
	output="$("$PHPSTAN_DIFF" --base=HEAD 2>&1)" || status=$?
	expect status "$status" 0
	expect_contains output "$output" '[OK] No errors on changed lines (base: HEAD @ '
}

# The filter, run on its own, diffs against the merge-base with --base, without
# looking at its remote-tracking counterparts.
test_filter_standalone() {
	setup_repos
	git fetch -q upstream
	git merge -q --no-edit upstream/trunk
	pd_filter
	expect status "$status" 1
	expect errors "$errors" "$own_errors u.php:1"
	expect used "$used" trunk
	pd_filter --base=upstream/trunk --exclude=mine.php
	expect errors "$errors" 'b.php:4'
	expect used "$used" upstream/trunk
	pd_filter --base=HEAD
	expect status "$status" 0
	expect errors "$errors" ''
	expect used "$used" HEAD
	pd_filter --used-base=HEAD
	expect status "$status" 2
	expect_contains stderr "$stderr" 'unknown option: --used-base=HEAD'
}

# Installed as a symlink (e.g. in ~/bin), it still finds its library.
test_symlinked_install() {
	setup_repos
	mkdir "$scratch/bin"
	ln -s "$PHPSTAN_DIFF" "$scratch/bin/phpstan-diff"
	status=0
	"$scratch/bin/phpstan-diff" --format=json >/dev/null 2>&1 || status=$?
	expect status "$status" 1
}

test_phpstan_failure() {
	setup_repos
	PHPSTAN_DIFF_ANALYSE_CMD="$TESTS_DIR/no-such-phpstan" pd
	expect status "$status" 2
	expect_contains stderr "$stderr" 'PHPStan did not produce JSON output'
	PHPSTAN_DIFF_ANALYSE_CMD='echo Something went wrong' pd
	expect status "$status" 2
	expect_contains stderr "$stderr" 'Something went wrong'
}

# Changed files that PHPStan's excludePaths cover are named in a note (from
# `phpstan dump-parameters`, run alongside the analysis), and if that leaves
# nothing to analyze, the run passes.
test_excluded_by_config() {
	setup_repos
	mkdir -p "$scratch/bin" lib
	ln -sf "$TESTS_DIR/fake-phpstan" "$scratch/bin/phpstan"
	put lib/l.php '<?php // lib'
	commit_all 'Add lib/l.php'
	export FAKE_PHPSTAN_EXCLUDE=lib PHPSTAN_DIFF_ANALYSE_CMD="$scratch/bin/phpstan analyse"
	pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	expect_contains stderr "$stderr" 'excludes 1 of the 3 files in scope, so it was not analyzed:'
	expect_contains stderr "$stderr" 'lib/l.php'
	pd --include=lib
	expect status "$status" 0
	expect_contains stderr "$stderr" 'excludes the only file in scope, so nothing was analyzed:'
}

# A Composer script can run PHPStan with each of several configurations, printing
# a report for each, one after another. They're merged, without duplicates.
test_several_reports() {
	setup_repos
	FAKE_PHPSTAN_RUNS=3 pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	FAKE_PHPSTAN_RUNS=3 pd_filter --base=trunk
	expect "filter status" "$status" 1
	expect "filter errors" "$errors" "$own_errors"
	PHPSTAN_DIFF_ANALYSE_CMD='echo {} trailing text' pd
	expect "status with text after the JSON" "$status" 2
	expect_contains stderr "$stderr" 'PHPStan did not produce JSON output'
}

# From a subdirectory, the project's own PHPStan (vendor/bin/phpstan at the top
# level) is found and run.
test_project_phpstan_from_subdirectory() {
	setup_repos
	mkdir -p vendor/bin sub
	ln -s "$TESTS_DIR/fake-phpstan" vendor/bin/phpstan
	put sub/s.php '<?php // sub'
	commit_all 'Add sub/s.php'
	cd sub
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors s.php:1"
}

# A "phpstan" Composer script that lists one script per PHPStan configuration
# runs each of them, even when an earlier one finds errors (after which
# Composer itself would stop), and merges their reports. Each configuration
# here reports one of the two files, so both reports are needed. The scripts
# can be listed one level further down, too.
test_composer_script_per_configuration() {
	if ! command -v composer >/dev/null; then
		echo '    (skipped: composer is not installed)'
		return 0
	fi
	setup_repos
	export COMPOSER_HOME="$scratch/composer-home"
	cat > composer.json <<-EOF
		{
			"scripts": {
				"phpstan": ["@phpstan:default", "@phpstan:strict"],
				"phpstan:default": ["@putenv FAKE_PHPSTAN_FILES=b.php", "$TESTS_DIR/fake-phpstan analyse"],
				"phpstan:strict": ["@putenv FAKE_PHPSTAN_FILES=mine.php", "$TESTS_DIR/fake-phpstan analyse"]
			}
		}
	EOF
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	cat > composer.json <<-EOF
		{
			"scripts": {
				"phpstan": "@phpstan:all",
				"phpstan:all": ["@phpstan:default", "@phpstan:strict"],
				"phpstan:default": ["@putenv FAKE_PHPSTAN_FILES=b.php", "$TESTS_DIR/fake-phpstan analyse"],
				"phpstan:strict": ["@putenv FAKE_PHPSTAN_FILES=mine.php", "$TESTS_DIR/fake-phpstan analyse"]
			}
		}
	EOF
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect "nested status" "$status" 1
	expect "nested errors" "$errors" "$own_errors"
	# The same, for a Composer script that $PHPSTAN_DIFF_ANALYSE_CMD runs.
	PHPSTAN_DIFF_ANALYSE_CMD='composer phpstan --' pd
	expect "custom command status" "$status" 1
	expect "custom command errors" "$errors" "$own_errors"
	PHPSTAN_DIFF_ANALYSE_CMD="php $(command -v composer) phpstan --" pd
	expect "php composer status" "$status" 1
	expect "php composer errors" "$errors" "$own_errors"
	# --level reaches each script's PHPStan.
	PHPSTAN_DIFF_ANALYSE_CMD='' pd --level=3
	expect "level status" "$status" 1
	expect "level errors" "$errors" 'b.php:4@3 mine.php:1@3'
}

# The configuration that a "phpstan" Composer script passes with -c is the one
# whose excludePaths are named in the note: when the script is run as such,
# from the Composer file that $COMPOSER names, and with `composer run-script`.
test_composer_script_configuration_excludes() {
	if ! command -v composer >/dev/null; then
		echo '    (skipped: composer is not installed)'
		return 0
	fi
	setup_repos
	export COMPOSER_HOME="$scratch/composer-home"
	mkdir -p "$scratch/bin" lib
	ln -sf "$TESTS_DIR/fake-phpstan" "$scratch/bin/phpstan"
	put lib/l.php '<?php // lib'
	put fake.neon lib
	commit_all 'Add lib/l.php'
	cat > composer.dev.json <<-EOF
		{ "scripts": { "phpstan": "$scratch/bin/phpstan analyse -c fake.neon" } }
	EOF
	export COMPOSER=composer.dev.json
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	expect_contains stderr "$stderr" 'excludes 1 of the 3 files in scope, so it was not analyzed:'
	expect_contains stderr "$stderr" 'lib/l.php'
	PHPSTAN_DIFF_ANALYSE_CMD='composer run-script phpstan --' pd
	expect "run-script status" "$status" 1
	expect_contains "run-script stderr" "$stderr" 'lib/l.php'
	PHPSTAN_DIFF_ANALYSE_CMD="php $(command -v composer) phpstan --" pd
	expect "php composer status" "$status" 1
	expect_contains "php composer stderr" "$stderr" 'lib/l.php'
	# An absolute $COMPOSER makes its directory the project directory, from a
	# subdirectory too (where fake.neon, relative to it, wouldn't be found).
	export COMPOSER="$PWD/composer.dev.json"
	cd lib
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect "absolute COMPOSER status" "$status" 1
	expect "absolute COMPOSER errors" "$errors" "$own_errors"
	expect_contains "absolute COMPOSER stderr" "$stderr" 'l.php'
}

# With one Composer script per PHPStan configuration, a file counts as excluded
# only if every configuration excludes it.
test_excludes_per_configuration() {
	if ! command -v composer >/dev/null; then
		echo '    (skipped: composer is not installed)'
		return 0
	fi
	setup_repos
	export COMPOSER_HOME="$scratch/composer-home"
	mkdir -p "$scratch/bin" lib
	ln -sf "$TESTS_DIR/fake-phpstan" "$scratch/bin/phpstan"
	put lib/l.php '<?php // lib'
	put fake.neon lib
	commit_all 'Add lib/l.php'
	cat > composer.json <<-EOF
		{
			"scripts": {
				"phpstan": ["@phpstan:default", "@phpstan:strict"],
				"phpstan:default": "$scratch/bin/phpstan analyse -c fake.neon",
				"phpstan:strict": "$scratch/bin/phpstan analyse"
			}
		}
	EOF
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect status "$status" 1
	expect errors "$errors" "b.php:4 l.php:1 mine.php:1"
	expect_not_contains stderr "$stderr" 'excludes'
	cat > composer.json <<-EOF
		{
			"scripts": {
				"phpstan": ["@phpstan:default", "@phpstan:strict"],
				"phpstan:default": "$scratch/bin/phpstan analyse -c fake.neon",
				"phpstan:strict": "$scratch/bin/phpstan analyse -c fake.neon"
			}
		}
	EOF
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect "status when both exclude" "$status" 1
	expect "errors when both exclude" "$errors" "$own_errors"
	expect_contains "stderr when both exclude" "$stderr" 'excludes 1 of the 3 files in scope'
}

# From a package of a monorepo, whose composer.json doesn't run PHPStan, the
# root project's PHPStan is found and run.
test_project_phpstan_in_monorepo() {
	setup_repos
	mkdir -p vendor/bin packages/foo
	ln -s "$TESTS_DIR/fake-phpstan" vendor/bin/phpstan
	put packages/foo/composer.json '{ "name": "example/foo" }'
	put packages/foo/p.php '<?php // package'
	commit_all 'Add packages/foo'
	cd packages/foo
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors p.php:1"
}

# From a package of a monorepo with its own PHPStan configuration, PHPStan runs
# from the package, so that its configuration applies, though the PHPStan it runs
# is the root project's.
test_package_configuration() {
	setup_repos
	mkdir -p vendor/bin packages/foo/generated
	ln -s "$TESTS_DIR/fake-phpstan" vendor/bin/phpstan
	put packages/foo/phpstan.neon generated
	put packages/foo/p.php '<?php // package'
	put packages/foo/generated/g.php '<?php // generated'
	commit_all 'Add packages/foo'
	cd packages/foo
	PHPSTAN_DIFF_ANALYSE_CMD='' pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors p.php:1"
	expect_contains stderr "$stderr" 'generated/g.php'
}

# $PHPSTAN_DIFF_ANALYSE_CMD runs from the current directory, so relative paths in
# it mean what they would on the command line.
test_custom_command_from_subdirectory() {
	setup_repos
	mkdir -p vendor/bin sub
	ln -s "$TESTS_DIR/fake-phpstan" vendor/bin/phpstan
	put sub/s.php '<?php // sub'
	commit_all 'Add sub/s.php'
	cd sub
	PHPSTAN_DIFF_ANALYSE_CMD='../vendor/bin/phpstan analyse' pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors s.php:1"
}

# The project's PHPStan is found in a Composer bin-dir that composer.json moves,
# with a vendor-dir and the {$vendor-dir} placeholder.
test_custom_vendor_dir() {
	setup_repos
	mkdir -p lib/vendor/tools
	ln -s "$TESTS_DIR/fake-phpstan" lib/vendor/tools/phpstan
	put composer.json '{ "config": { "vendor-dir": "lib/vendor", "bin-dir": "{$vendor-dir}/tools" } }'
	PHPSTAN_DIFF_ANALYSE_CMD='' COMPOSER_HOME="$scratch/composer-home" pd
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
}

# Without --base or $PHPSTAN_DIFF_BASE, the base is the default branch: what the
# remote's HEAD points to, else the first of develop, main, master and trunk that
# exists. In GitHub Actions, $GITHUB_BASE_REF (a pull request's target) comes first.
test_default_branch() {
	local repos="$scratch/default-branch"
	rm -rf "$repos"
	mkdir -p "$repos"
	cd "$repos"
	git init -q -b main project
	(
		cd project
		put a.php '<?php' '// one'
		commit_all 'Add a.php'
	)
	git clone -q project clone
	cd clone
	git checkout -q -b feature
	put a.php '<?php' '// one MINE'
	commit_all 'Feature'
	pd
	expect "remote HEAD status" "$status" 1
	expect "remote HEAD errors" "$errors" 'a.php:2'
	expect "remote HEAD used" "$used" main

	cd "$repos"
	git init -q -b master local
	cd local
	put a.php '<?php' '// one'
	commit_all 'Add a.php'
	git checkout -q -b feature
	put a.php '<?php' '// one MINE'
	commit_all 'Feature'
	pd
	expect "master status" "$status" 1
	expect "master used" "$used" master

	# With both develop and master, it's develop (git-flow's integration branch).
	git branch -q develop master
	pd
	expect "develop status" "$status" 1
	expect "develop used" "$used" develop

	# In GitHub Actions, a pull request's target branch comes first.
	GITHUB_BASE_REF=master pd
	expect "GITHUB_BASE_REF status" "$status" 1
	expect "GITHUB_BASE_REF used" "$used" master

	git branch -q -D develop
	git branch -q -m master release
	pd
	expect "no default status" "$status" 2
	expect_contains "no default stderr" "$stderr" 'could not work out the default branch'

	# A base set by PHPSTAN_DIFF_BASE that doesn't exist says where it came from.
	PHPSTAN_DIFF_BASE=gone pd
	expect "bad PHPSTAN_DIFF_BASE status" "$status" 2
	expect_contains "bad PHPSTAN_DIFF_BASE stderr" "$stderr" "base 'gone' is not a valid commit or branch (it was set by \$PHPSTAN_DIFF_BASE)"
	GITHUB_BASE_REF=gone pd
	expect "bad GITHUB_BASE_REF status" "$status" 2
	expect_contains "bad GITHUB_BASE_REF stderr" "$stderr" 'Fetch that branch'

	# Names are tried in order, each locally and then on origin: a develop that's
	# only on origin beats a local main.
	cd "$repos"
	git -C project branch -q develop
	git clone -q project gitflow
	cd gitflow
	git remote set-head origin -d
	git checkout -q -b feature origin/develop
	put a.php '<?php' '// one MINE'
	commit_all 'Feature'
	pd
	expect "remote develop over local main status" "$status" 1
	expect "remote develop over local main used" "$used" origin/develop

	# A fork whose only remote isn't "origin", with a stale master on it: the
	# local trunk wins over the remote-only master, which couldn't be resolved.
	cd "$repos"
	git init -q -b trunk fork-project
	(
		cd fork-project
		put a.php '<?php' '// one'
		commit_all 'Add a.php'
		git branch -q master
	)
	git clone -q fork-project fork-clone
	cd fork-clone
	git remote rename origin fork
	git remote set-head fork -d
	git checkout -q -b feature
	put a.php '<?php' '// one MINE'
	commit_all 'Feature'
	pd
	expect "local over remote-only status" "$status" 1
	expect "local over remote-only used" "$used" trunk

	# A branch that's only on a remote other than origin and upstream isn't
	# guessed, since it couldn't be resolved.
	git checkout -q --no-track -b work fork/master
	git branch -q -D trunk feature
	pd
	expect "remote-only master status" "$status" 2
	expect_contains "remote-only master stderr" "$stderr" 'could not work out the default branch'
	expect_not_contains "remote-only master stderr (not invalid)" "$stderr" 'not a valid commit'

	# But with no origin or upstream, a name on the remote that the checked-out
	# branch tracks is used, even without a HEAD for that remote.
	git branch -q -u fork/master
	pd
	expect "tracked remote name used" "$used" fork/master
	git branch -q --unset-upstream

	# Without origin, the HEAD of the remote that the checked-out branch tracks
	# gives the default branch, here one that's not in the list of names.
	cd "$repos"
	git init -q -b stable named-project
	(
		cd named-project
		put a.php '<?php' '// one'
		commit_all 'Add a.php'
	)
	git clone -q named-project named-clone
	cd named-clone
	git remote rename origin fork
	git checkout -q -b feature fork/stable
	put a.php '<?php' '// one MINE'
	commit_all 'Feature'
	pd
	expect "tracked remote HEAD status" "$status" 1
	expect "tracked remote HEAD used" "$used" stable

	# If the local branch doesn't track that remote's branch, it's the
	# remote-tracking branch.
	git branch -q --unset-upstream stable
	pd
	expect "tracked remote HEAD, untracked local status" "$status" 1
	expect "tracked remote HEAD, untracked local used" "$used" fork/stable

	# Likewise with no local branch of that name.
	git branch -q -D stable
	pd
	expect "tracked remote HEAD, no local status" "$status" 1
	expect "tracked remote HEAD, no local used" "$used" fork/stable

	# Even if a branch under that name (stable/hotfix) tracks it.
	git branch -q --track stable/hotfix fork/stable
	pd
	expect "tracked remote HEAD, nested local used" "$used" fork/stable
	git branch -q -D stable/hotfix

	# Where an upstream remote exists, the tracked remote is likely someone's
	# fork, so its HEAD isn't used.
	git remote add upstream "$repos/named-project"
	pd
	expect "tracked remote ignored status" "$status" 2
	expect_contains "tracked remote ignored stderr" "$stderr" 'could not work out the default branch'
	git remote remove upstream

	# A guessed base that turns out not to exist says it was auto-detected.
	git symbolic-ref refs/remotes/fork/HEAD refs/remotes/fork/gone
	pd
	expect "guessed bad base status" "$status" 2
	expect_contains "guessed bad base stderr" "$stderr" 'auto-detected as the default branch'
}

# By default, lines whose only changes are in the amount of whitespace don't count
# as changed (as with `git diff -b`), and files with only such changes aren't
# analyzed, with notes on both; --no-ignore-whitespace counts them.
test_ignore_whitespace() {
	local repos="$scratch/whitespace"
	rm -rf "$repos"
	git init -q "$repos"
	cd "$repos"
	put a.php '<?php' 'function f() {' '	foo();' '	bar();' '	baz();' "	\$s = 'a b';" '}'
	put w.php '<?php' 'function g() {' '	qux();' '}'
	commit_all 'Add a.php and w.php'
	git checkout -q -b feature
	# In a.php, lines 3 and 6 are new, line 4 is re-indented, line 5 is
	# re-indented and changed, line 7 gets trailing spaces, and line 8 loses a
	# space within a string. w.php is only re-indented.
	put a.php '<?php' 'function f() {' '	if ($x) {' '		foo();' '		bar(1);' '	}' '	baz();  ' "	\$s = 'ab';" '}'
	put w.php '<?php' 'function g() {' '		qux();' '}'
	commit_all 'Wrap foo() and bar() in an if'

	pd
	expect status "$status" 1
	expect errors "$errors" 'a.php:3 a.php:5 a.php:6 a.php:8'
	expect_contains stderr "$stderr" '2 errors in the analyzed files are on lines whose only changes are in whitespace'
	expect_contains stderr "$stderr" '1 changed .php file in scope wasn'"'"'t analyzed, as its only changes are in whitespace'
	expect_contains stderr "$stderr" 'w.php'
	pd --no-ignore-whitespace
	expect "counted status" "$status" 1
	expect "counted errors" "$errors" 'a.php:3 a.php:4 a.php:5 a.php:6 a.php:7 a.php:8 w.php:3'
	expect_not_contains "counted stderr" "$stderr" 'whitespace'

	# Errors about ignores that don't fit a --level, on a line whose only changes
	# are in whitespace, aren't counted as hidden by that, since they'd never be
	# shown.
	FAKE_PHPSTAN_IGNORE_LINE=4 pd --level=3
	expect "ignore on whitespace line errors" "$errors" 'a.php:3@3 a.php:5@3 a.php:6@3 a.php:8@3'
	expect_contains "ignore on whitespace line stderr" "$stderr" '2 errors in the analyzed files are on lines whose only changes are in whitespace'

	# phpstan-diff-filter is handed both files, so it filters w.php's errors too.
	pd_filter
	expect "filter errors" "$errors" 'a.php:3 a.php:5 a.php:6 a.php:8'
	expect_contains "filter stderr" "$stderr" '3 errors in the analyzed files are on lines whose only changes are in whitespace'
	pd_filter --no-ignore-whitespace
	expect "filter counted errors" "$errors" 'a.php:3 a.php:4 a.php:5 a.php:6 a.php:7 a.php:8 w.php:3'

	# When the only changes are in whitespace, nothing is analyzed, and the note
	# says why.
	git checkout -q -b whitespace-only trunk
	put w.php '<?php' 'function g() {' '		qux();' '}'
	commit_all 'Re-indent'
	pd
	expect "whitespace-only status" "$status" 0
	expect_contains "whitespace-only stderr" "$stderr" 'The only changed .php file in scope changed only in whitespace, so nothing was analyzed'
	expect_not_contains "whitespace-only stderr" "$stderr" 'No changed .php files'
	pd --no-ignore-whitespace
	expect "whitespace-only counted status" "$status" 1
	expect "whitespace-only counted errors" "$errors" 'w.php:3'
}

# --- Run ---------------------------------------------------------------------

all_tests=()
while IFS= read -r name; do
	all_tests+=( "$name" )
done < <(grep -oE '^test_[a-z_]+\(\)' "${BASH_SOURCE[0]}" | tr -d '()')
if [ "$#" -gt 0 ]; then
	selected_tests=()
	for name in "$@"; do selected_tests+=( "test_${name#test_}" ); done
else
	selected_tests=( "${all_tests[@]}" )
fi

failures=0
for name in "${selected_tests[@]}"; do
	set +e
	(
		set -e
		"$name"
		exit "$test_failed"
	) > "$scratch/output" 2>&1
	result=$?
	set -e
	if [ "$result" -eq 0 ]; then
		echo "ok - ${name#test_}"
	else
		echo "not ok - ${name#test_}"
		sed 's/^/  /' "$scratch/output"
		failures=$((failures + 1))
	fi
done

echo
if [ "$failures" -eq 0 ]; then
	echo "All ${#selected_tests[@]} tests passed."
else
	echo "$failures of ${#selected_tests[@]} tests failed."
	exit 1
fi
