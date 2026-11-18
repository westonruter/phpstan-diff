#!/usr/bin/env bash
#
# Tests for phpstan-diff. Each test builds throwaway git repositories (an
# "upstream" repo, a fork of it, and a working clone with a feature branch) and
# checks which lines phpstan-diff reports, with tests/fake-phpstan standing in
# for PHPStan: it reports an error on every line of each file it's given.
#
# Usage: tests/run-tests.sh [<test-name>...]
#
# Exit status: 0 if every test passes, 1 otherwise.

# shellcheck disable=SC2016  # The PHP snippets are single-quoted on purpose.

set -euo pipefail

TESTS_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd -P)"
PHPSTAN_DIFF="$TESTS_DIR/../bin/phpstan-diff"
FILTER="$TESTS_DIR/../bin/phpstan-diff-filter"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/phpstan-diff-tests.XXXXXX")"
trap 'rm -rf "$scratch"' EXIT

# Keep the user's git configuration (commit signing, hooks, aliases, …) and
# environment out of the tests.
unset GIT_CONFIG_COUNT GIT_CONFIG_PARAMETERS GIT_SSH GIT_SSH_COMMAND GIT_DIR GIT_WORK_TREE PHPSTAN_DIFF_BASE
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
	local json summary
	status=0
	json="$("$PHPSTAN_DIFF" --format=json "$@" 2>"$scratch/stderr")" || status=$?
	stderr="$(cat "$scratch/stderr")"
	errors=""
	used=""
	[ "${json:0:1}" = "{" ] || return 0
	summary="$(printf '%s' "$json" | php -r '
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

# expect <description> <actual> <expected>
expect() {
	if [ "$2" != "$3" ]; then
		printf '    %s: expected "%s", got "%s"\n' "$1" "$3" "$2"
		[ -n "$stderr" ] && printf '    stderr: %s\n' "$stderr"
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

# The branch merged upstream/trunk, but its remote-tracking ref is gone (as if
# never fetched): --fetch creates it, and it's used.
test_fetch_creates_missing_refs() {
	setup_repos
	git fetch -q upstream
	git merge -q --no-edit upstream/trunk
	git update-ref -d refs/remotes/upstream/trunk
	pd --fetch
	expect upstream/trunk "$(git rev-parse --verify --quiet refs/remotes/upstream/trunk)" \
		"$(git -C "$scratch/repos/upstream" rev-parse trunk)"
	expect errors "$errors" "$own_errors"
	expect used "$used" upstream/trunk
}

test_fetch_remote_tracking_base() {
	setup_repos
	git update-ref -d refs/remotes/origin/trunk
	pd --base=origin/trunk
	expect "status without --fetch" "$status" 2
	pd --fetch --base=origin/trunk
	expect "status with --fetch" "$status" 1
	expect used "$used" origin/trunk
	expect_contains errors "$errors" 'mine.php:1'
}

test_fetch_failure_only_warns() {
	setup_repos
	git remote set-url upstream "$scratch/no-such-repository"
	pd --fetch
	expect status "$status" 1
	expect errors "$errors" "$own_errors"
	expect_contains stderr "$stderr" 'could not fetch refs/heads/trunk from upstream'
}

# OpenSSH is run with BatchMode, so it can't stop to prompt.
test_fetch_ssh_batch_mode() {
	setup_repos
	local fake_ssh="$scratch/bin/ssh"
	mkdir -p "$scratch/bin"
	printf '#!/bin/sh\necho "$*" >> "%s"\nexit 255\n' "$scratch/ssh.log" > "$fake_ssh"
	chmod +x "$fake_ssh"
	rm -f "$scratch/ssh.log"
	git remote set-url upstream ssh://git@example.invalid/upstream.git
	GIT_SSH="$fake_ssh" pd --fetch
	expect status "$status" 1
	expect_contains "ssh arguments" "$(cat "$scratch/ssh.log" 2>/dev/null)" '-o BatchMode=yes'
}

# Diffs against diverged merge-bases may place the same hunk differently.
test_combine_ranges() {
	local result
	result="$(php -r '
		preg_match("/^function pd_combine_ranges.*?^}\n/ms", file_get_contents($argv[1]), $match);
		eval($match[0]);
		$cases = array(
			array( array( array( 10, 14 ) ), array( array( 11, 15 ) ), array( array( 10, 15 ) ) ), // A slid hunk: union.
			array( array( array( 4, 6 ) ), array( array( 4, 4 ) ), array( array( 4, 4 ) ) ),       // Adjacent fork-only lines: shorter.
			array( array( array( 4, 4 ), array( 20, 22 ) ), array( array( 4, 4 ) ), array( array( 4, 4 ) ) ),
			array( array( array( 1, 2 ) ), array( array( 5, 6 ) ), array() ),
			array( array( array( 1, 10 ) ), array( array( 2, 3 ), array( 7, 8 ) ), array( array( 2, 3 ), array( 7, 8 ) ) ),
		);
		foreach ($cases as $index => $case) {
			$got = pd_combine_ranges($case[0], $case[1]);
			if ($got !== $case[2]) {
				echo "case $index: got ", json_encode($got), "\n";
			}
		}
	' "$FILTER")"
	expect pd_combine_ranges "$result" ''
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
