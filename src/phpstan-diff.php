<?php
/**
 * The code behind bin/phpstan-diff and bin/phpstan-diff-filter: running git and
 * PHPStan, choosing the base, working out the changed lines, and filtering and
 * printing a PHPStan JSON report. Requiring this file only defines functions
 * and constants, in the WestonRuter\PHPStanDiff namespace.
 */

declare(strict_types=1);

namespace WestonRuter\PHPStanDiff;

const EXIT_OK       = 0;
const EXIT_ERRORS   = 1;
const EXIT_INTERNAL = 2;

/**
 * The name to prefix messages with: that of the script being run (its PROGRAM
 * constant).
 */
function program(): string {
	return defined(__NAMESPACE__ . '\\PROGRAM') ? (string) constant(__NAMESPACE__ . '\\PROGRAM') : 'phpstan-diff';
}

/**
 * Print a fatal error and exit.
 *
 * @return never
 */
function fail(string $message): void {
	fwrite(STDERR, program() . ": $message\n");
	exit(EXIT_INTERNAL);
}

/**
 * Print the usage text: the first docblock of $file, without its " * " margin.
 */
function print_usage(string $file): void {
	if (preg_match('~/\*\*\n(.*?)\n\s*\*/~s', (string) file_get_contents($file), $matches)) {
		echo preg_replace('~^ \*(?: |$)~m', '', $matches[1]), "\n";
	}
}

// --- Running commands --------------------------------------------------------

function null_device(): string {
	return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
}

/**
 * Run commands concurrently, without a shell, with stdin from the null device,
 * and wait for them all to finish.
 *
 * On SIGTERM (where the pcntl extension is available), any that are still
 * running are stopped too. (Ctrl-C reaches them anyway, as they're in the
 * terminal's process group.)
 *
 * @template K of array-key
 * @param array<K, list<string>> $commands
 * @return array<K, array{status: int, stdout: string, stderr: string}>
 */
function run_all(array $commands): array {
	static $running = array();
	static $trapped = false;
	if (!$trapped && function_exists('pcntl_async_signals')) {
		$trapped = true;
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, static function () use (&$running): void {
			foreach ($running as $process) {
				@proc_terminate($process);
			}
			exit(143);
		});
	}

	$stdout  = array(); // Command key => what it printed, likewise for:
	$stderr  = array();
	$streams = array(); // The pipes being read.
	$owners  = array(); // For each pipe, its command's key and whether it's stdout.
	$running = array();
	foreach ($commands as $key => $command) {
		$stdout[$key] = '';
		$stderr[$key] = '';
		$descriptors   = array( array( 'file', null_device(), 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) );
		$process       = @proc_open($command, $descriptors, $pipes);
		if (!is_resource($process)) {
			$stderr[$key] = "could not run {$command[0]}\n";
			continue;
		}
		$running[$key] = $process;
		$streams[]      = $pipes[1];
		$owners[]       = array( $key, true );
		$streams[]      = $pipes[2];
		$owners[]       = array( $key, false );
	}

	// Read every pipe as output arrives, so that no process blocks on a full one.
	$failedSelects = 0;
	while ($streams) {
		$read   = $streams;
		$write  = null;
		$except = null;
		if (@stream_select($read, $write, $except, null) === false) {
			// Interrupted by a signal, presumably; give up only if it persists.
			if (++$failedSelects > 100) {
				break;
			}
			continue;
		}
		foreach ($read as $index => $stream) {
			$chunk = fread($stream, 65536);
			if ($chunk === false || ($chunk === '' && feof($stream))) {
				fclose($stream);
				unset($streams[$index]);
				continue;
			}
			[$key, $isStdout] = $owners[$index];
			if ($isStdout) {
				$stdout[$key] .= $chunk;
			} else {
				$stderr[$key] .= $chunk;
			}
		}
	}
	foreach ($streams as $stream) {
		fclose($stream);
	}
	$results = array();
	foreach ($commands as $key => $command) {
		$results[$key] = array(
			'status' => isset($running[$key]) ? proc_close($running[$key]) : 127,
			'stdout' => $stdout[$key],
			'stderr' => $stderr[$key],
		);
	}
	$running = array();
	return $results;
}

/**
 * Run a command, without a shell.
 *
 * @param list<string> $command
 * @return array{status: int, stdout: string, stderr: string}
 */
function run(array $command): array {
	return run_all(array( $command ))[0];
}

/**
 * Run a git command, returning its stdout split into lines (newlines stripped).
 *
 * @param list<string> $arguments
 * @param-out int $exitCode
 * @return list<string>
 */
function git(array $arguments, ?int &$exitCode = null): array {
	$result   = run(array_merge(array( 'git' ), $arguments));
	$exitCode = $result['status'];
	$stdout   = rtrim($result['stdout'], "\n");
	return $stdout === '' ? array() : explode("\n", $stdout);
}

/**
 * True if a git command succeeds.
 *
 * @param list<string> $arguments
 */
function git_succeeds(array $arguments): bool {
	git($arguments, $exitCode);
	return $exitCode === 0;
}

/**
 * The first line a git command prints, or '' if it fails or prints nothing.
 *
 * @param list<string> $arguments
 */
function git_line(array $arguments): string {
	$lines = git($arguments, $exitCode);
	return ($exitCode === 0 && isset($lines[0])) ? trim($lines[0]) : '';
}

/**
 * The path of executable $name on $PATH, or null if there's none.
 */
function which(string $name): ?string {
	$extensions = PHP_OS_FAMILY === 'Windows' ? array( '.exe', '.bat', '.cmd', '' ) : array( '' );
	foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
		if ($directory === '') {
			continue;
		}
		foreach ($extensions as $extension) {
			$candidate = $directory . DIRECTORY_SEPARATOR . $name . $extension;
			if (is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}
	}
	return null;
}

// --- Locating PHPStan --------------------------------------------------------

/**
 * The PHPStan binary to use directly: a project-local vendor binary (honoring a
 * custom Composer bin-dir), else a global install, else null.
 */
function find_phpstan_binary(): ?string {
	$binDirectory = getenv('COMPOSER_BIN_DIR');
	if (is_string($binDirectory) && $binDirectory !== '' && is_executable_file("$binDirectory/phpstan")) {
		return "$binDirectory/phpstan";
	}
	if (is_executable_file('vendor/bin/phpstan')) {
		return 'vendor/bin/phpstan';
	}
	if (which('composer') !== null) {
		$result = run(array( 'composer', 'config', 'bin-dir' ));
		$binDirectory = trim($result['stdout']);
		if ($result['status'] === 0 && $binDirectory !== '' && is_executable_file("$binDirectory/phpstan")) {
			return "$binDirectory/phpstan";
		}
	}
	return which('phpstan') !== null ? 'phpstan' : null;
}

function is_executable_file(string $path): bool {
	return is_file($path) && is_executable($path);
}

/**
 * The command that runs `phpstan analyse`: $PHPSTAN_DIFF_ANALYSE_CMD, else the
 * "phpstan" Composer script, else the PHPStan binary.
 *
 * @return list<string>
 */
function resolve_analyse_command(): array {
	$command = getenv('PHPSTAN_DIFF_ANALYSE_CMD');
	if (is_string($command) && trim($command) !== '') {
		// Used verbatim; split on whitespace (good enough for "composer phpstan --" etc.).
		return preg_split('/\s+/', trim($command)) ?: array();
	}
	if (which('composer') !== null) {
		$result = run(array( 'composer', 'run-script', '--list' ));
		if ($result['status'] === 0 && preg_match('/^\s+phpstan(\s|$)/m', $result['stdout'])) {
			return array( 'composer', 'phpstan', '--' );
		}
	}
	$binary = find_phpstan_binary();
	if ($binary === null) {
		fail(
			"could not find PHPStan.\n"
			. "  Install it (composer require --dev phpstan/phpstan), add a 'phpstan' Composer script,\n"
			. '  or set PHPSTAN_DIFF_ANALYSE_CMD to the command that runs it.'
		);
	}
	return array( $binary, 'analyse', '--memory-limit=2G' );
}

/**
 * A `phpstan dump-parameters --json` command that resolves the same
 * configuration as $analyseCommand, so the filter can tell which files PHPStan's
 * excludePaths skipped; or null if the PHPStan binary can't be found. Looks for
 * the binary and any -c/--configuration among the analyse command's words (or,
 * for the Composer script, the script's own).
 *
 * @param list<string> $analyseCommand
 * @return list<string>|null
 */
function resolve_dump_command(array $analyseCommand): ?array {
	$words = $analyseCommand;
	if ($analyseCommand[0] === 'composer') {
		// A string script, e.g. "@php ./vendor/bin/phpstan analyse --memory-limit=2G".
		$result = run(array( 'composer', 'config', 'scripts.phpstan' ));
		$words  = $result['status'] === 0 ? (preg_split('/\s+/', trim($result['stdout']), -1, PREG_SPLIT_NO_EMPTY) ?: array()) : array();
	}
	$binary              = null;
	$configuration       = array();
	$expectConfiguration = false;
	foreach ($words as $word) {
		if ($expectConfiguration) {
			$configuration       = array( '-c', $word );
			$expectConfiguration = false;
		} elseif ($word === '-c' || $word === '--configuration') {
			$expectConfiguration = true;
		} elseif (str_starts_with($word, '--configuration=')) {
			$configuration = array( '-c', substr($word, 16) );
		} elseif (str_starts_with($word, '-c') && strlen($word) > 2) {
			$configuration = array( '-c', substr($word, 2) );
		} elseif (in_array(substr((string) strrchr('/' . $word, '/'), 1), array( 'phpstan', 'phpstan.phar' ), true)) {
			$binary = $word;
		}
	}
	$binary = $binary ?? find_phpstan_binary();
	if ($binary === null) {
		return null;
	}
	return array_merge(array( $binary, 'dump-parameters', '--json' ), $configuration);
}

// --- Paths -------------------------------------------------------------------

/**
 * Resolve the "+++ " path from a unified-diff file header to a repo-relative
 * path, or null when the new side is /dev/null (a deleted file).
 */
function parse_header_path(string $path): ?string {
	$tabPosition = strpos($path, "\t");
	if ($tabPosition !== false) {
		$path = substr($path, 0, $tabPosition);
	}
	if ($path === '/dev/null') {
		return null;
	}
	if (strlen($path) >= 2 && $path[0] === '"' && str_ends_with($path, '"')) {
		$path = stripcslashes(substr($path, 1, -1));
	}
	if (str_starts_with($path, 'b/')) {
		$path = substr($path, 2);
	}
	return $path;
}

/**
 * Express $path relative to $base when possible.
 */
function relative_to(string $path, string $base): string {
	$base = rtrim($base, '/');
	if ($base !== '' && str_starts_with($path, $base . '/')) {
		return substr($path, strlen($base) + 1);
	}
	return $path;
}

/**
 * Express repo-relative $file relative to the directory that git's
 * `rev-parse --show-prefix` gave as $prefix (e.g. "src/" in the repo's src/).
 */
function relative_to_prefix(string $file, string $prefix): string {
	$prefixParts = $prefix === '' ? array() : explode('/', rtrim($prefix, '/'));
	$fileParts   = explode('/', $file);
	while ($prefixParts && count($fileParts) > 1 && $prefixParts[0] === $fileParts[0]) {
		array_shift($prefixParts);
		array_shift($fileParts);
	}
	return str_repeat('../', count($prefixParts)) . implode('/', $fileParts);
}

/**
 * True if repo-relative $file matches the path/glob $pattern: an exact file, any
 * descendant of a directory (trailing "/" optional), or a glob (where "*" may
 * span "/").
 */
function path_matches(string $file, string $pattern): bool {
	$trimmedPattern = rtrim($pattern, '/');
	if ($file === $trimmedPattern) {
		return true;
	}
	if ($trimmedPattern !== '' && str_starts_with($file, $trimmedPattern . '/')) {
		return true;
	}
	return fnmatch($trimmedPattern, $file);
}

/**
 * True if repo-relative $file is kept by the --include patterns (if any) and not
 * dropped by an --exclude pattern.
 *
 * @param list<string> $includes
 * @param list<string> $excludes
 */
function path_selected(string $file, array $includes, array $excludes): bool {
	if ($includes) {
		$included = false;
		foreach ($includes as $pattern) {
			if (path_matches($file, $pattern)) {
				$included = true;
				break;
			}
		}
		if (!$included) {
			return false;
		}
	}
	foreach ($excludes as $pattern) {
		if (path_matches($file, $pattern)) {
			return false;
		}
	}
	return true;
}

/**
 * Normalize an absolute path the way PHPStan's FileHelper does: collapse "//" and
 * "/./", resolve "..", but don't resolve symlinks.
 */
function normalize_path(string $path): string {
	$parts = array();
	foreach (explode('/', $path) as $part) {
		if ($part === '' || $part === '.') {
			continue;
		}
		if ($part === '..') {
			array_pop($parts);
			continue;
		}
		$parts[] = $part;
	}
	return '/' . implode('/', $parts);
}

/**
 * Read the analyse excludes (excludePaths.analyse + analyseAndScan) from the output
 * of `phpstan dump-parameters --json`, or null if it can't be parsed.
 *
 * @return list<string>|null
 */
function read_analyse_excludes(string $raw): ?array {
	if ($raw === '') {
		return null;
	}
	// PHPStan prints the dump through Symfony Console, which unescapes "\<" to "<"
	// and so leaves invalid escapes behind (e.g. a regex's "\\<" becomes "\<").
	// Re-double any backslash that doesn't start a valid JSON escape.
	$raw = (string) preg_replace_callback(
		'/\\\\(.)/s',
		static fn(array $matches): string => str_contains('"\\/bfnrtu', $matches[1]) ? $matches[0] : '\\\\' . $matches[1],
		$raw
	);
	$parameters = json_decode($raw, true);
	if (!is_array($parameters) || !isset($parameters['excludePaths']) || !is_array($parameters['excludePaths'])) {
		return null;
	}
	$excludes = array();
	foreach (array( 'analyse', 'analyseAndScan' ) as $kind) {
		foreach ((array) ($parameters['excludePaths'][$kind] ?? array()) as $exclude) {
			if (is_string($exclude) && $exclude !== '') {
				$excludes[] = $exclude;
			}
		}
	}
	return $excludes;
}

/**
 * True if PHPStan would exclude absolute $file from analysis given its resolved
 * $excludes. Mirrors PHPStan\File\FileExcluder::isExcludedFromAnalysing(): an
 * fnmatch() pattern if it contains any of *?[], else an exact file or a directory
 * prefix (entries that exist as neither are ignored).
 *
 * @param list<string> $excludes
 */
function is_excluded_by_phpstan(string $file, array $excludes): bool {
	$file = normalize_path($file);
	foreach ($excludes as $exclude) {
		$trailingSeparator = str_ends_with($exclude, '/');
		$normalized        = normalize_path($exclude) . ($trailingSeparator ? '/' : '');
		if (preg_match('~[*?[\]]~', $normalized)) {
			if (fnmatch($normalized, $file)) {
				return true;
			}
		} elseif (is_file($normalized)) {
			if ($file === $normalized) {
				return true;
			}
		} elseif (is_dir($normalized)) {
			if (str_starts_with($file, rtrim($normalized, '/') . '/')) {
				return true;
			}
		}
	}
	return false;
}

// --- Choosing the base -------------------------------------------------------

/**
 * The full ref of each remote-tracking counterpart of $branch worth considering,
 * if it exists: the local branch's configured upstream, then $branch on the
 * conventional "origin" and "upstream" remotes. Other remotes (a collaborator's
 * fork, say) are left out, since their branch may well have merged this
 * branch's own commits.
 *
 * @return list<string>
 */
function remote_counterparts(string $branch): array {
	$counterparts = array();
	$upstream     = '';
	if (git_succeeds(array( 'show-ref', '--verify', '--quiet', "refs/heads/$branch" ))) {
		$upstream = git_line(array( 'for-each-ref', '--format=%(upstream)', "refs/heads/$branch" ));
		// (An upstream outside refs/remotes/ is another local branch.)
		if (str_starts_with($upstream, 'refs/remotes/') && git_succeeds(array( 'show-ref', '--verify', '--quiet', $upstream ))) {
			$counterparts[] = $upstream;
		}
	}
	$remotes = git(array( 'remote' ));
	foreach (array( 'origin', 'upstream' ) as $remote) {
		$ref = "refs/remotes/$remote/$branch";
		if (in_array($remote, $remotes, true) && $ref !== $upstream && git_succeeds(array( 'show-ref', '--verify', '--quiet', $ref ))) {
			$counterparts[] = $ref;
		}
	}
	return $counterparts;
}

/**
 * Work out what to diff against for the requested $base.
 *
 * When $base is a local branch, its remote-tracking counterparts are considered
 * too (as they are for a branch name that only exists on remotes, e.g. a CI
 * clone with origin/trunk but no local trunk). Every candidate's merge-base with
 * HEAD is in this branch's history, and the commits behind it normally aren't
 * this branch's changes (e.g. commits that origin/trunk has, and this branch
 * merged, but the local trunk lacks). So the newest is taken: the merge-base(s)
 * that no other one contains. If several remain (say, this branch merged both
 * origin/trunk with fork-only commits and a newer upstream/trunk), a line counts
 * as changed only if it changed relative to all of them.
 *
 * A candidate whose merge-base is HEAD itself (e.g. a trunk that this branch has
 * already been merged into) would hide every change, so it's skipped, unless
 * every candidate is like that (e.g. --base=HEAD), or it's the branch that is
 * checked out (on trunk, the diff is against trunk itself: uncommitted changes).
 *
 * @return array{used: list<array{ref: string, commit: string}>, note: string}
 *         The refs the diff is taken from (the first name wins where several
 *         share a merge-base), each with the commit to diff against, and why
 *         they differ from the requested one ('' if they don't).
 */
function select_bases(string $base): array {
	$isLocalBranch = git_succeeds(array( 'show-ref', '--verify', '--quiet', "refs/heads/$base" ));
	$isCommit      = git_succeeds(array( 'rev-parse', '--verify', '--quiet', "$base^{commit}" ));

	$counterparts = array();
	if ($isLocalBranch || (!$isCommit && git_succeeds(array( 'check-ref-format', '--branch', $base )))) {
		$counterparts = remote_counterparts($base);
	}

	// Candidates: the requested base itself (unless it's a branch that only exists
	// on remotes), then its remote-tracking counterparts. Full refs avoid
	// ambiguity, e.g. with a tag of the same name.
	$candidates   = array(); // list of array{name: string, ref: string}
	$requestedRef = '';
	if ($isLocalBranch) {
		$requestedRef = "refs/heads/$base";
	} elseif (!$counterparts) {
		$requestedRef = $base;
	}
	if ($requestedRef !== '' && git_succeeds(array( 'rev-parse', '--verify', '--quiet', "$requestedRef^{commit}" ))) {
		$candidates[] = array( 'name' => $base, 'ref' => $requestedRef );
	}
	foreach ($counterparts as $ref) {
		$candidates[] = array( 'name' => substr($ref, strlen('refs/remotes/')), 'ref' => $ref );
	}
	if (!$candidates) {
		fail("base '$base' is not a valid commit or branch (see --base).");
	}

	$headCommit         = git_line(array( 'rev-parse', '--verify', '--quiet', 'HEAD' ));
	$headBranch         = git_line(array( 'symbolic-ref', '--quiet', 'HEAD' ));
	$hasOlderMergeBase  = false;
	$requestedMergeBase = '';
	foreach ($candidates as $index => $candidate) {
		$mergeBase                         = git_line(array( 'merge-base', $candidate['ref'], 'HEAD' ));
		$candidates[$index]['merge_base'] = $mergeBase;
		if ($mergeBase !== '' && $mergeBase !== $headCommit) {
			$hasOlderMergeBase = true;
		}
		if ($candidate['ref'] === $requestedRef) {
			$requestedMergeBase = $mergeBase;
		}
	}

	$mergeBases = array(); // commit => name
	foreach ($candidates as $candidate) {
		$mergeBase = $candidate['merge_base'];
		if ($mergeBase === '' || isset($mergeBases[$mergeBase])) {
			continue;
		}
		if ($hasOlderMergeBase && $mergeBase === $headCommit && $candidate['ref'] !== $headBranch) {
			continue;
		}
		$mergeBases[$mergeBase] = $candidate['name'];
	}

	// Keep the newest: those no other one contains (in candidate order).
	$used = array();
	if ($mergeBases) {
		$independent = git(array_merge(array( 'merge-base', '--independent' ), array_map('strval', array_keys($mergeBases))));
		foreach ($mergeBases as $commit => $name) {
			if (in_array((string) $commit, $independent, true)) {
				$used[] = array( 'ref' => $name, 'commit' => (string) $commit );
			}
		}
	} else {
		// No merge-base at all (unrelated histories, or a shallow clone whose
		// history doesn't reach back far enough): diff against the base itself.
		$used[] = array(
			'ref'    => $candidates[0]['name'],
			'commit' => git_line(array( 'rev-parse', '--verify', '--quiet', $candidates[0]['ref'] . '^{commit}' )),
		);
	}

	$usedNames = implode(' + ', array_column($used, 'ref')); // E.g. "upstream/trunk + origin/trunk".
	$note      = '';
	if (count($used) > 1) {
		$note = "This branch merged $usedNames, whose histories have diverged, so only lines changed relative to all of them count.";
	} elseif ($usedNames !== $base && $requestedRef !== '') {
		// (A base that only exists on remotes, as in CI, needs no note.)
		if ($requestedMergeBase === '') {
			$note = "The local $base shares no history with this branch, so the diff is against $usedNames instead.";
		} elseif ($requestedMergeBase === $headCommit) {
			$note = "The local $base already contains this branch, so the diff is against $usedNames instead.";
		} else {
			$note = "This branch contains commits from $usedNames that the local $base lacks, so the diff is against $usedNames instead.";
		}
	}
	if ($note !== '' && $isLocalBranch) {
		$note = rtrim($note, '.') . " (to use $base as-is, pass --base=refs/heads/$base).";
	}
	if (!$mergeBases && git_line(array( 'rev-parse', '--is-shallow-repository' )) === 'true') {
		// Then the diff is against the base's tip, so its changes since this branch
		// diverged count as changed lines too.
		$name = $candidates[0]['name'];
		$note = "This is a shallow clone, and its history doesn't reach where this branch and $name diverged, so the diff is against $name itself and includes its newer changes. Fetch more history (e.g. git fetch --unshallow, or fetch-depth: 0 with actions/checkout).";
	}

	return array( 'used' => $used, 'note' => $note );
}

// --- Changed lines -----------------------------------------------------------

/**
 * The repo-relative .php files (added, copied, modified or renamed) that differ
 * from every one of $targets, in the working tree or, if $staged, the index.
 * (Relative to the top level, wherever this is run from.)
 *
 * @param list<string> $targets
 * @return list<string>
 */
function changed_php_files(array $targets, bool $staged): array {
	$files = null;
	foreach ($targets as $target) {
		$arguments = array( 'git', 'diff', '-z', '--name-only', '--diff-filter=ACMR' );
		if ($staged) {
			$arguments[] = '--cached';
		}
		// (":(top)" so that, from a subdirectory, the whole repository is
		// still covered, as with a plain `git diff`.)
		array_push($arguments, $target, '--', ':(top)*.php');
		$output      = rtrim(run($arguments)['stdout'], "\0");
		$targetFiles = $output === '' ? array() : explode("\0", $output);
		$files       = $files === null ? $targetFiles : array_values(array_intersect($files, $targetFiles));
	}
	return $files ?? array();
}

/**
 * Run `git diff` against $target and return the changed new-side line ranges.
 *
 * @param list<string> $paths
 * @return array<string, list<array{int, int, string}>> abs-path => [start, end, added text] per hunk.
 */
function changed_ranges(string $target, bool $staged, array $paths, string $topLevel): array {
	$diffArguments = array( 'diff', '--no-color', '--unified=0', '--src-prefix=a/', '--dst-prefix=b/' );
	if ($staged) {
		// Compare the index (staged snapshot) rather than the working tree, so
		// unstaged edits are excluded. The base ref stays the merge-base, so
		// committed branch changes are still in scope; pass --base=HEAD to narrow
		// this down to staged changes only.
		$diffArguments[] = '--cached';
	}
	$diffArguments[] = $target;
	if ($paths) {
		$diffArguments[] = '--';
		foreach ($paths as $path) {
			$diffArguments[] = $path;
		}
	}
	$diffOutput = git($diffArguments, $diffStatus);
	if ($diffStatus !== 0) {
		fail("`git diff` failed — is " . var_export($target, true) . " a valid commit?");
	}

	$changed     = array(); // abs-path => list of [start, end] per hunk
	/** @var array<string, array<int, string>> $addedText abs-path => hunk index => the lines it adds */
	$addedText   = array();
	$hunkIndex   = 0;
	$currentFile = null;   // absolute path of the file currently being parsed (null = skip / between files)
	$inHunk      = false;  // true within a hunk that adds lines
	$inHeader    = false;  // true between "diff --git" and the first "@@" of that file section
	foreach ($diffOutput as $line) {
		if (str_starts_with($line, 'diff --git ')) {
			$currentFile = null;
			$inHeader    = true;
			$inHunk      = false;
			continue;
		}
		if ($inHeader) {
			if (str_starts_with($line, '+++ ')) {
				$relativePath = parse_header_path(substr($line, 4));
				if ($relativePath === null) {
					$currentFile = null;
				} else {
					$currentFile = ($relativePath === '' || $relativePath[0] === '/') ? $relativePath : $topLevel . '/' . $relativePath;
					if (!isset($changed[$currentFile])) {
						$changed[$currentFile] = array();
					}
				}
				continue;
			}
			if (str_starts_with($line, '@@ ')) {
				$inHeader = false; // fall through to hunk handling
			} else {
				continue; // index / mode / rename / "--- a/..." header lines
			}
		}
		if ($currentFile !== null && str_starts_with($line, '@@ ')) {
			$inHunk = false;
			if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
				$start = (int) $matches[1];
				$count = isset($matches[2]) ? (int) $matches[2] : 1;
				if ($count > 0) {
					$changed[$currentFile][] = array( $start, $start + $count - 1 );
					$hunkIndex               = count($changed[$currentFile]) - 1;
					$inHunk                  = true;
				}
			}
			continue;
		}
		// Keep each hunk's added lines, so combine_ranges() can recognize a hunk
		// that git placed differently in two diffs. ("-" lines are ignored.)
		if ($inHunk && $currentFile !== null && str_starts_with($line, '+')) {
			$addedText[$currentFile][$hunkIndex] = ($addedText[$currentFile][$hunkIndex] ?? '') . substr($line, 1) . "\n";
		}
	}
	// (Files with no hunks that add lines, i.e. pure deletions, mode changes and
	// pure renames, are left out.)
	$ranges = array();
	foreach ($changed as $file => $hunks) {
		foreach ($hunks as $index => [$start, $end]) {
			$ranges[$file][] = array( $start, $end, $addedText[$file][$index] ?? '' );
		}
	}
	return $ranges;
}

/**
 * Combine the changed ranges from diffs against two merge-bases, keeping the
 * lines changed relative to both: the intersection of the ranges. The exception
 * is a hunk that git placed differently in the two diffs: it can slide a hunk
 * past identical lines, such as a "}" or a blank line, depending on the context.
 * Overlapping hunks with the same added text are taken to be that, and their
 * union is kept, rather than dropping the ends of the branch's own change.
 *
 * @param list<array{int, int, string}> $first   Ascending [start, end, added text] ranges.
 * @param list<array{int, int, string}> $second  Likewise.
 * @return list<array{int, int, string}> (The added text of a combined range is
 *                                       only kept for a slid hunk.)
 */
function combine_ranges(array $first, array $second): array {
	$kept = array();
	foreach ($first as $firstRange) {
		foreach ($second as $secondRange) {
			if ($secondRange[1] < $firstRange[0] || $secondRange[0] > $firstRange[1]) {
				continue; // No overlap.
			}
			if ($firstRange[2] !== '' && $firstRange[2] === $secondRange[2]) {
				$kept[] = array( min($firstRange[0], $secondRange[0]), max($firstRange[1], $secondRange[1]), $firstRange[2] );
			} else {
				$kept[] = array( max($firstRange[0], $secondRange[0]), min($firstRange[1], $secondRange[1]), '' );
			}
		}
	}
	// Sort and merge overlapping or adjacent ranges.
	usort($kept, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
	$result = array();
	foreach ($kept as $range) {
		$last = count($result) - 1;
		if ($last >= 0 && $range[0] <= $result[$last][1] + 1) {
			if ($range[1] > $result[$last][1]) {
				$result[$last][1] = $range[1];
				$result[$last][2] = '';
			}
		} else {
			$result[] = $range;
		}
	}
	return $result;
}

// --- Filtering and printing the report ---------------------------------------

/**
 * Wrap a string in an ANSI SGR sequence when color is enabled.
 */
function style(string $text, string $codes, bool $enabled): string {
	return $enabled ? "\033[{$codes}m$text\033[0m" : $text;
}

/**
 * The repository's top-level directory, or a fatal error outside one.
 */
function top_level(): string {
	$topLevel = git_line(array( 'rev-parse', '--show-toplevel' ));
	if ($topLevel === '') {
		fail('not inside a git repository');
	}
	return $topLevel;
}

/**
 * Decode a PHPStan JSON report, filling in missing keys, or return null if it
 * isn't one.
 *
 * @return array<string, mixed>|null
 */
function decode_report(string $json): ?array {
	$report = json_decode($json, true);
	if (!is_array($report)) {
		return null;
	}
	$report += array(
		'files'  => array(),
		'errors' => array(),
		'totals' => array( 'errors' => 0, 'file_errors' => 0 ),
	);
	if (!is_array($report['files'])) {
		fail('unexpected PHPStan JSON shape: "files" is not an object');
	}
	return $report;
}

/**
 * Keep only the messages of a PHPStan $report that fall on changed lines, print
 * them (as a PHPStan-style table or as JSON), and return the exit status: 1 if
 * any errors remain, else 0.
 *
 * @param array<string, mixed> $report As from decode_report().
 * @param array{
 *     base: string,
 *     usedBases?: list<array{ref: string, commit: string}>,
 *     baseNote?: string,
 *     staged?: bool,
 *     format?: string,
 *     paths?: list<string>,
 *     includes?: list<string>,
 *     excludes?: list<string>,
 *     nothingAnalysed?: string|null,
 *     phpstanParameters?: string|null,
 * } $options
 *     - base: the base asked for.
 *     - usedBases: the refs to diff from, with the commits to diff against, as
 *       from select_bases(); a line counts as changed only if it changed
 *       relative to every one. Default: the merge-base with base, or else base.
 *     - baseNote: shown as a note (e.g. why usedBases differ from base).
 *     - staged: diff the index rather than the working tree.
 *     - format: 'table' or 'json'.
 *     - paths: restrict the diff to these (what PHPStan was given).
 *     - includes, excludes: repo-relative paths or globs that reported files
 *       must, or mustn't, match.
 *     - nothingAnalysed: why PHPStan wasn't run, for a note, with an empty
 *       report: 'no-changed-files', or 'excluded-by-config' (then the paths
 *       are listed as the files PHPStan's configuration excluded).
 *     - phpstanParameters: the output of `phpstan dump-parameters --json`, to
 *       name any paths its excludePaths cover.
 */
function filter_report(array $report, array $options): int {
	$base              = $options['base'];
	$usedBases         = $options['usedBases'] ?? array();
	$baseNote          = $options['baseNote'] ?? '';
	$staged            = $options['staged'] ?? false;
	$format            = $options['format'] ?? 'table';
	$paths             = $options['paths'] ?? array();
	$includes          = $options['includes'] ?? array();
	$excludes          = $options['excludes'] ?? array();
	$nothingAnalysed   = $options['nothingAnalysed'] ?? null;
	$phpstanParameters = $options['phpstanParameters'] ?? null;

	$topLevel = top_level();

	// The commits to diff against: the bases chosen already, else the merge-base
	// with <base>, else <base> itself. With several (merge-bases of bases whose
	// histories diverged), a line counts as changed only if it changed relative to
	// every one of them, so commits that came from any one base don't count.
	if (!$usedBases) {
		$commit = git_line(array( 'merge-base', $base, 'HEAD' ));
		if ($commit === '') {
			$commit = git_line(array( 'rev-parse', '--verify', '--quiet', $base . '^{commit}' ));
			$commit = $commit !== '' ? $commit : $base;
		}
		$usedBases[] = array( 'ref' => $base, 'commit' => $commit );
	}
	$diffTargets = array_column($usedBases, 'commit');

	/** @var array<string, list<array{int, int, string}>>|null $changed  abs-path => list of inclusive [start, end, added text] new-side ranges */
	$changed = null;
	foreach ($diffTargets as $diffTarget) {
		$targetChanged = changed_ranges($diffTarget, $staged, $paths, $topLevel);
		if ($changed === null) {
			$changed = $targetChanged;
			continue;
		}
		foreach ($changed as $file => $ranges) {
			$ranges = isset($targetChanged[$file]) ? combine_ranges($ranges, $targetChanged[$file]) : array();
			if ($ranges) {
				$changed[$file] = $ranges;
			} else {
				unset($changed[$file]);
			}
		}
	}

	// --- Filter the report ---

	$keptFiles = array();
	$keptCount = 0;
	foreach ($report['files'] as $absolutePath => $data) {
		$ranges = $changed[$absolutePath] ?? null;
		if ($ranges === null) {
			$resolvedPath = @realpath((string) $absolutePath);
			if ($resolvedPath !== false && isset($changed[$resolvedPath])) {
				$ranges = $changed[$resolvedPath];
			}
		}
		if ($ranges === null) {
			continue;
		}
		if ($includes || $excludes) {
			$relativePath = relative_to((string) $absolutePath, $topLevel);
			if ($relativePath === (string) $absolutePath) {
				// Key wasn't under the repo root (e.g. a symlinked path); resolve it,
				// mirroring the realpath fallback used for the changed-line lookup.
				$resolvedPath = @realpath((string) $absolutePath);
				if ($resolvedPath !== false) {
					$relativePath = relative_to($resolvedPath, $topLevel);
				}
			}
			if (!path_selected($relativePath, $includes, $excludes)) {
				continue;
			}
		}
		$messages = (is_array($data) && isset($data['messages']) && is_array($data['messages'])) ? $data['messages'] : array();
		$kept     = array();
		foreach ($messages as $message) {
			$lineNumber = (is_array($message) && isset($message['line'])) ? $message['line'] : null;
			if (!is_int($lineNumber) || $lineNumber <= 0) {
				continue;
			}
			foreach ($ranges as $range) {
				if ($lineNumber >= $range[0] && $lineNumber <= $range[1]) {
					$kept[] = $message;
					break;
				}
			}
		}
		if (!$kept) {
			continue;
		}
		$keptFiles[$absolutePath] = array( 'errors' => count($kept), 'messages' => $kept );
		$keptCount += count($kept);
	}

	$genericErrors = is_array($report['errors']) ? array_values($report['errors']) : array();
	$hasErrors     = ($keptCount > 0 || count($genericErrors) > 0);

	$report['files']  = $keptFiles;
	$report['errors'] = $genericErrors;
	$report['totals'] = array( 'errors' => count($genericErrors), 'file_errors' => $keptCount );

	// --- Output ---

	// Which of the files handed to PHPStan its own configuration excluded, if known.
	$excludedPaths = array();
	if ($nothingAnalysed === 'excluded-by-config') {
		$excludedPaths = $paths;
	} elseif ($nothingAnalysed === null && $phpstanParameters !== null && $paths) {
		$analyseExcludes = read_analyse_excludes($phpstanParameters);
		if ($analyseExcludes) {
			$workingDirectory = getcwd() ?: $topLevel;
			foreach ($paths as $path) {
				$absolutePath = str_starts_with($path, '/') ? $path : $workingDirectory . '/' . $path;
				if (is_file($absolutePath) && is_excluded_by_phpstan($absolutePath, $analyseExcludes)) {
					$excludedPaths[] = $path;
				}
			}
		}
	}

	// How the base was chosen (for the summary line and a note).
	// E.g. "upstream/trunk @ 1a2b3c4", or "upstream/trunk + origin/trunk @ 1a2b3c4 + 5d6e7f8".
	$shortCommits = array();
	foreach ($diffTargets as $diffTarget) {
		$shortCommit    = git_line(array( 'rev-parse', '--short', $diffTarget ));
		$shortCommits[] = $shortCommit !== '' ? $shortCommit : $diffTarget;
	}
	$baseLabel = implode(' + ', array_column($usedBases, 'ref')) . ' @ ' . implode(' + ', $shortCommits) . ($staged ? ', staged' : '');

	// Why some or all of the files in scope weren't analysed.
	$note      = '';
	$fileCount = count($paths);
	if ($nothingAnalysed === 'no-changed-files') {
		$note = 'No changed .php files in scope, so nothing was analysed.';
	} elseif ($excludedPaths) {
		$excludedCount = count($excludedPaths);
		if ($excludedCount === $fileCount) {
			$note = 'PHPStan\'s configuration (excludePaths) excludes ' . ($fileCount === 1 ? 'the only file' : 'all ' . $fileCount . ' files') . ' in scope, so nothing was analysed:';
		} else {
			$note = 'PHPStan\'s configuration (excludePaths) excludes ' . $excludedCount . ' of the ' . $fileCount . ' files in scope, so ' . ($excludedCount === 1 ? 'it was' : 'they were') . ' not analysed:';
		}
		foreach ($excludedPaths as $path) {
			$note .= "\n  " . $path;
		}
	}

	if ($format === 'json') {
		foreach (array( $baseNote, $note ) as $stderrNote) {
			if ($stderrNote !== '') {
				fwrite(STDERR, "phpstan-diff: $stderrNote\n"); // Keep stdout pure JSON.
			}
		}
		$report['files']        = $keptFiles ?: new \stdClass();
		$report['phpstan_diff'] = array(
			'base' => array(
				'requested' => $base,
				// Each ref the diff is taken from, with the commit diffed against: its
				// merge-base with HEAD, or the ref itself if it shares no history with
				// HEAD. With several, a line counts as changed only if it changed relative
				// to every one of them.
				'used'      => $usedBases,
				'staged'    => $staged,
			),
		);
		echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
		return $hasErrors ? EXIT_ERRORS : EXIT_OK;
	}

	// Table format.
	$useColor = (getenv('NO_COLOR') === false) && function_exists('stream_isatty') && @stream_isatty(STDOUT);

	$columns = (int) (getenv('COLUMNS') ?: 0);
	if ($columns <= 0) {
		$ttySize = @shell_exec('{ stty size < /dev/tty ; } 2>/dev/null');
		if (is_string($ttySize) && preg_match('/^\d+\s+(\d+)/', trim($ttySize), $matches)) {
			$columns = (int) $matches[1];
		}
	}
	if ($columns <= 0) {
		$columns = 120;
	}
	$columns = max(60, $columns);

	$workingDirectory = getcwd();
	if ($workingDirectory === false) {
		$workingDirectory = $topLevel;
	}

	$output = "\n";

	if ($keptFiles) {
		$lineColumnWidth = 4; // strlen('Line')
		foreach ($keptFiles as $data) {
			foreach ($data['messages'] as $message) {
				$lineColumnWidth = max($lineColumnWidth, strlen((string) $message['line']));
			}
		}
		$indent             = str_repeat(' ', $lineColumnWidth + 5); // "  " + line col + "   "
		$messageColumnWidth = max(20, $columns - $lineColumnWidth - 6);
		$rule               = ' ' . str_repeat('-', $lineColumnWidth + 2) . ' ' . str_repeat('-', $messageColumnWidth + 2) . "\n";

		foreach ($keptFiles as $absolutePath => $data) {
			$displayPath = relative_to((string) $absolutePath, $workingDirectory);
			$output .= $rule;
			$output .= '  ' . str_pad('Line', $lineColumnWidth) . '   ' . style($displayPath, '1', $useColor) . "\n";
			$output .= $rule;
			foreach ($data['messages'] as $message) {
				$lineNumberText = (string) $message['line'];
				$segments       = explode("\n", wordwrap((string) $message['message'], $messageColumnWidth, "\n", true));
				$first          = true;
				foreach ($segments as $segment) {
					$left = $first
						? style(str_pad($lineNumberText, $lineColumnWidth), '32', $useColor)
						: str_repeat(' ', $lineColumnWidth);
					$output .= '  ' . $left . '   ' . $segment . "\n";
					$first   = false;
				}
				if (!empty($message['tip'])) {
					foreach (explode("\n", wordwrap('💡 ' . $message['tip'], $messageColumnWidth, "\n", true)) as $segment) {
						$output .= $indent . style($segment, '36', $useColor) . "\n";
					}
				}
				if (!empty($message['identifier'])) {
					$output .= $indent . style('🪪  ' . $message['identifier'], '2', $useColor) . "\n";
				}
			}
			$output .= $rule;
			$output .= "\n";
		}
	}

	if ($genericErrors) {
		foreach ($genericErrors as $error) {
			$text = is_string($error) ? $error : (string) json_encode($error);
			$output .= ' ' . style('✗', '31', $useColor) . ' ' . $text . "\n";
		}
		$output .= "\n";
	}

	// The base note is prose, so wrap it; the other note may list file paths, which
	// must stay one per line.
	foreach (array( wordwrap($baseNote, $columns - 2), $note ) as $tableNote) {
		if ($tableNote !== '') {
			$output .= style(' ' . str_replace("\n", "\n ", $tableNote), '2', $useColor) . "\n\n";
		}
	}

	if ($hasErrors) {
		$total = $keptCount + count($genericErrors);
		$label = ' [ERROR] Found ' . $total . ' error' . ($total === 1 ? '' : 's') . ' on changed lines (base: ' . $baseLabel . ') ';
		$output .= style($label, '1;37;41', $useColor) . "\n\n";
	} else {
		$output .= style(' [OK] No errors on changed lines (base: ' . $baseLabel . ') ', '1;37;42', $useColor) . "\n\n";
	}

	echo $output;
	return $hasErrors ? EXIT_ERRORS : EXIT_OK;
}

// --- bin/phpstan-diff --------------------------------------------------------

/**
 * Parse the command line of bin/phpstan-diff, run PHPStan and filter its report;
 * return the exit status.
 *
 * @param list<string> $arguments
 */
function main(array $arguments, string $usageFile): int {
	$base         = getenv('PHPSTAN_DIFF_BASE');
	$base         = (is_string($base) && $base !== '') ? $base : 'trunk';
	$staged       = false;
	$wholeProject = false;
	$format       = 'table';
	$paths        = array();
	$includes     = array();
	$excludes     = array();

	$argumentCount = count($arguments);
	for ($index = 0; $index < $argumentCount; $index++) {
		$argument = $arguments[$index];
		if ($argument === '--') {
			array_push($paths, ...array_slice($arguments, $index + 1));
			break;
		}
		if (preg_match('/^--(base|include|exclude|format)(?:=(.*))?$/s', $argument, $matches)) {
			if (!isset($matches[2])) {
				if (!isset($arguments[$index + 1])) {
					fail("--{$matches[1]} requires a value");
				}
				$matches[2] = $arguments[++$index];
			}
			if ($matches[1] === 'base') {
				$base = $matches[2];
			} elseif ($matches[1] === 'include') {
				$includes[] = $matches[2];
			} elseif ($matches[1] === 'exclude') {
				$excludes[] = $matches[2];
			} else {
				$format = $matches[2];
			}
		} elseif ($argument === '--staged' || $argument === '--cached') {
			$staged = true;
		} elseif ($argument === '--whole-project') {
			$wholeProject = true;
		} elseif ($argument === '--changed') {
			// Deprecated: now the default.
		} elseif ($argument === '-h' || $argument === '--help') {
			print_usage($usageFile);
			return EXIT_OK;
		} elseif (str_starts_with($argument, '--')) {
			fail("unknown option: $argument");
		} else {
			$paths[] = $argument;
		}
	}
	if ($format !== 'table' && $format !== 'json') {
		fail("--format must be 'table' or 'json'");
	}

	top_level(); // Fails outside a git repository.
	$analyseCommand = resolve_analyse_command();
	$bases          = select_bases($base);
	$filterOptions  = array(
		'base'      => $base,
		'usedBases' => $bases['used'],
		'baseNote'  => $bases['note'],
		'staged'    => $staged,
		'format'    => $format,
		'includes'  => $includes,
		'excludes'  => $excludes,
	);
	$emptyReport = array(
		'totals' => array( 'errors' => 0, 'file_errors' => 0 ),
		'files'  => array(),
		'errors' => array(),
	);

	// By default, analyse only the changed .php files that --include/--exclude
	// keep (so PHPStan never analyses the others, rather than their errors being
	// hidden later). With several merge-bases, a file is in scope only if it
	// changed relative to all of them.
	if (!$wholeProject && !$paths) {
		$prefix = git_line(array( 'rev-parse', '--show-prefix' ));
		foreach (changed_php_files(array_column($bases['used'], 'commit'), $staged) as $file) {
			if (path_selected($file, $includes, $excludes)) {
				$paths[] = relative_to_prefix($file, $prefix);
			}
		}
		if (!$paths) {
			return filter_report($emptyReport, $filterOptions + array( 'nothingAnalysed' => 'no-changed-files' ));
		}
	}
	$filterOptions['paths'] = $paths;

	// When PHPStan is handed specific files, it silently skips any its
	// excludePaths cover. Dump its resolved parameters alongside the analysis
	// (concurrently, since it boots PHPStan too) so the filter can name the
	// skipped files.
	$commands = array(
		'analyse' => array_merge($analyseCommand, array( '--error-format=json', '--no-progress' ), $paths ? array_merge(array( '--' ), $paths) : array()),
	);
	$dumpCommand = $paths ? resolve_dump_command($analyseCommand) : null;
	if ($dumpCommand !== null) {
		$commands['dump'] = $dumpCommand;
	}
	$results = run_all($commands);
	$stdout  = $results['analyse']['stdout'];
	$stderr  = $results['analyse']['stderr'];

	$report = decode_report($stdout);
	if ($report === null) {
		// When PHPStan's excludePaths covers every path it was handed, it emits no
		// JSON, just "[ERROR] No files found to analyse." (A partially excluded set
		// is fine: the excluded paths are silently skipped.) That's not a failure
		// of the change.
		if ($paths && (str_contains($stderr, 'No files found to analyse') || str_contains($stdout, 'No files found to analyse'))) {
			return filter_report($emptyReport, $filterOptions + array( 'nothingAnalysed' => 'excluded-by-config' ));
		}
		fwrite(STDERR, program() . ": PHPStan did not produce JSON output:\n");
		if (trim($stdout) !== '') {
			fwrite(STDERR, rtrim($stdout, "\n") . "\n");
		}
		fwrite(STDERR, $stderr);
		return EXIT_INTERNAL;
	}

	if (isset($results['dump']) && $results['dump']['status'] === 0) {
		$filterOptions['phpstanParameters'] = $results['dump']['stdout'];
	}
	return filter_report($report, $filterOptions);
}
