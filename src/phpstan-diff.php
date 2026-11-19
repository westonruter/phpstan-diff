<?php

/**
 * The code behind bin/phpstan-diff and bin/phpstan-diff-filter: running git and
 * PHPStan, choosing the base, working out the changed lines, and filtering and
 * printing a PHPStan JSON report.
 *
 * Requiring this file only defines functions and constants, in the
 * WestonRuter\PHPStanDiff namespace; it has no side effects.
 *
 * @package   WestonRuter\PHPStanDiff
 * @author    Weston Ruter
 * @copyright 2026 Weston Ruter
 * @license   https://opensource.org/license/mit MIT
 * @link      https://github.com/westonruter/phpstan-diff
 */

declare(strict_types=1);

namespace WestonRuter\PHPStanDiff;

use JetBrains\PhpStorm\NoReturn;
use stdClass;

/**
 * Exit status: no errors on the changed lines.
 *
 * @var int
 */
const EXIT_OK = 0;

/**
 * Exit status: errors remain on the changed lines.
 *
 * @var int
 */
const EXIT_ERRORS = 1;

/**
 * Exit status: something went wrong (bad usage, git or PHPStan failing, ...).
 *
 * @var int
 */
const EXIT_INTERNAL = 2;

/**
 * Get, and optionally set, the name that messages are prefixed with: that of the
 * script being run.
 *
 * @param non-empty-string|null $name The script's name, to set it; null to only get it.
 * @return non-empty-string The name (default: "phpstan-diff").
 */
function program(?string $name = null): string
{
	/** @var non-empty-string $program */
	static $program = 'phpstan-diff';
	if ($name !== null) {
		$program = $name;
	}
	return $program;
}

/**
 * Print a fatal error, prefixed with the program's name, to stderr and exit with
 * EXIT_INTERNAL.
 *
 * (The return type can't be declared as never, since PHP 8.0 is supported.)
 *
 * @param non-empty-string $message What went wrong.
 * @return void
 * @phpstan-return never
 */
#[NoReturn]
function fail(string $message): void
{
	fwrite(STDERR, program() . ": $message\n");
	exit(EXIT_INTERNAL);
}

/**
 * Print the usage text: the first docblock of $file, without its " * " margin,
 * up to its tags (e.g. @package).
 *
 * Nothing is printed if $file can't be read or has no docblock.
 *
 * @param non-empty-string $file The script whose usage to print.
 */
function print_usage(string $file): void
{
	if (preg_match('~/\*\*\n(.*?)\n\s*\*/~s', (string) file_get_contents($file), $matches) !== 1) {
		return;
	}
	$usage = (string) preg_replace('~^ \*(?: |$)~m', '', $matches[1]);
	$usage = (string) preg_replace('~^@.*~ms', '', $usage);
	echo rtrim($usage), "\n";
}

// --- Running commands --------------------------------------------------------

/**
 * The path of the null device, for a command's stdin.
 *
 * @phpstan-pure
 * @return string The path.
 * @phpstan-return '/dev/null'|'NUL'
 */
function null_device(): string
{
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
 * A command that can't be started gets status 127 (as in a shell), with an
 * explanation in its stderr.
 *
 * @template K of array-key
 * @param array<K, non-empty-list<string>> $commands Each command's program, then its arguments, by key.
 * @return array<K, array{status: int, stdout: string, stderr: string}> Each command's exit status
 *                                                                     and output, by key.
 */
function run_all(array $commands): array
{
	/** @var array<array-key, resource> $running The processes still running, for the SIGTERM handler. */
	static $running = [];
	static $trapped = false;
	if ($trapped !== true && function_exists('pcntl_async_signals')) {
		$trapped = true;
		pcntl_async_signals(true);
		// (pcntl is optional, hence only suggested in composer.json, and checked for above.)
		/** @noinspection PhpComposerExtensionStubsInspection */
		pcntl_signal(SIGTERM, static function () use (&$running): void {
			foreach ($running as $process) {
				@proc_terminate($process);
			}
			exit(143);
		});
	}

	$stdout  = []; // Command key => what it printed, likewise for:
	$stderr  = [];
	$streams = []; // The pipes being read.
	$owners  = []; // For each pipe, its command's key and whether it's stdout.
	$running = [];
	foreach ($commands as $key => $command) {
		$stdout[$key] = '';
		$stderr[$key] = '';
		$descriptors   = [['file', null_device(), 'r'], ['pipe', 'w'], ['pipe', 'w']];
		$process       = @proc_open($command, $descriptors, $pipes);
		if (!is_resource($process)) {
			$stderr[$key] = "could not run $command[0]\n";
			continue;
		}
		$running[$key] = $process;
		$streams[]      = $pipes[1];
		$owners[]       = [$key, true];
		$streams[]      = $pipes[2];
		$owners[]       = [$key, false];
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
	$results = [];
	foreach (array_keys($commands) as $key) {
		$results[$key] = [
			'status' => isset($running[$key]) ? proc_close($running[$key]) : 127,
			'stdout' => $stdout[$key],
			'stderr' => $stderr[$key],
		];
	}
	$running = [];
	return $results;
}

/**
 * Run a command, without a shell, and wait for it to finish.
 *
 * @see run_all()
 *
 * @param non-empty-list<string> $command The program, then its arguments.
 * @return array{status: int, stdout: string, stderr: string} Its exit status and output.
 */
function run(array $command): array
{
	return run_all([$command])[0];
}

/**
 * Run a git command, returning its stdout split into lines (newlines stripped).
 *
 * @param non-empty-list<string> $arguments The arguments to git, starting with the subcommand.
 * @param int|null               $exitCode  Receives git's exit status.
 * @param-out int $exitCode
 * @return list<string> The lines git printed (none if it printed nothing).
 */
function git(array $arguments, ?int &$exitCode = null): array
{
	$result   = run(array_merge(['git'], $arguments));
	$exitCode = $result['status'];
	$stdout   = rtrim($result['stdout'], "\n");
	return $stdout === '' ? [] : explode("\n", $stdout);
}

/**
 * Whether a git command succeeds (its output is discarded).
 *
 * @param non-empty-list<string> $arguments The arguments to git, starting with the subcommand.
 * @return bool Whether it exited with status 0.
 */
function git_succeeds(array $arguments): bool
{
	git($arguments, $exitCode);
	return $exitCode === 0;
}

/**
 * The first line a git command prints, trimmed.
 *
 * @param non-empty-list<string> $arguments The arguments to git, starting with the subcommand.
 * @return string The line, or '' if git fails or prints nothing.
 */
function git_line(array $arguments): string
{
	$lines = git($arguments, $exitCode);
	return $exitCode === 0 && isset($lines[0]) ? trim($lines[0]) : '';
}

/**
 * Find an executable on $PATH, as a shell would.
 *
 * @param non-empty-string $name The executable's name (without an extension on Windows).
 * @return non-empty-string|null Its path, or null if there's none.
 */
function which(string $name): ?string
{
	$extensions = PHP_OS_FAMILY === 'Windows' ? ['.exe', '.bat', '.cmd', ''] : [''];
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

/**
 * Split text into its whitespace-separated words.
 *
 * @phpstan-pure
 * @param string $text The text, e.g. a command line (quoting isn't understood).
 * @return list<non-empty-string> The words (none for blank text).
 */
function split_words(string $text): array
{
	$words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
	return $words === false ? [] : $words;
}

// --- Locating PHPStan --------------------------------------------------------

/**
 * Find the PHPStan binary to run directly.
 *
 * Looks for a project-local vendor binary (in $COMPOSER_BIN_DIR, vendor/bin, or
 * the bin-dir Composer is configured with), then a global install.
 *
 * @return non-empty-string|null Its path (or just "phpstan" for a global install), or null if
 *                               there's none.
 */
function find_phpstan_binary(): ?string
{
	$binDirectory = getenv('COMPOSER_BIN_DIR');
	if (is_string($binDirectory) && $binDirectory !== '' && is_executable_file("$binDirectory/phpstan")) {
		return "$binDirectory/phpstan";
	}
	if (is_executable_file('vendor/bin/phpstan')) {
		return 'vendor/bin/phpstan';
	}
	if (which('composer') !== null) {
		$result = run(['composer', 'config', 'bin-dir']);
		$binDirectory = trim($result['stdout']);
		if ($result['status'] === 0 && $binDirectory !== '' && is_executable_file("$binDirectory/phpstan")) {
			return "$binDirectory/phpstan";
		}
	}
	return which('phpstan') !== null ? 'phpstan' : null;
}

/**
 * Whether a path is an executable file (not a directory).
 *
 * @param non-empty-string $path The path to check.
 * @return bool Whether it's a file that can be executed.
 */
function is_executable_file(string $path): bool
{
	return is_file($path) && is_executable($path);
}

/**
 * Work out the command that runs `phpstan analyse`.
 *
 * That's $PHPSTAN_DIFF_ANALYSE_CMD, else the "phpstan" Composer script, else the
 * PHPStan binary. Exits with a fatal error if there's none of these.
 *
 * @return non-empty-list<non-empty-string> The program, then its arguments, to which the
 *                                          files to analyse can be appended.
 */
function resolve_analyse_command(): array
{
	$command = getenv('PHPSTAN_DIFF_ANALYSE_CMD');
	if (is_string($command)) {
		// Used verbatim; split on whitespace (good enough for "composer phpstan --" etc.).
		$words = split_words($command);
		if ($words !== []) {
			return $words;
		}
	}
	if (which('composer') !== null) {
		$result = run(['composer', 'run-script', '--list']);
		if ($result['status'] === 0 && preg_match('/^\s+phpstan(\s|$)/m', $result['stdout']) === 1) {
			return ['composer', 'phpstan', '--'];
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
	return [$binary, 'analyse', '--memory-limit=2G'];
}

/**
 * Work out a `phpstan dump-parameters --json` command that resolves the same
 * configuration as an analyse command, so the filter can tell which files
 * PHPStan's excludePaths skipped.
 *
 * Looks for the binary and any -c/--configuration among the analyse command's
 * words (or, for the Composer script, the script's own).
 *
 * @param non-empty-list<non-empty-string> $analyseCommand As from resolve_analyse_command().
 * @return non-empty-list<string>|null The program, then its arguments; or null if the PHPStan
 *                                     binary can't be found.
 */
function resolve_dump_command(array $analyseCommand): ?array
{
	$words = $analyseCommand;
	if ($analyseCommand[0] === 'composer') {
		// A string script, e.g. "@php ./vendor/bin/phpstan analyse --memory-limit=2G".
		$result = run(['composer', 'config', 'scripts.phpstan']);
		$words  = $result['status'] === 0 ? split_words($result['stdout']) : [];
	}
	$binary              = null;
	$configuration       = [];
	$expectConfiguration = false;
	foreach ($words as $word) {
		if ($expectConfiguration) {
			$configuration       = ['-c', $word];
			$expectConfiguration = false;
		} elseif ($word === '-c' || $word === '--configuration') {
			$expectConfiguration = true;
		} elseif (str_starts_with($word, '--configuration=')) {
			$configuration = ['-c', substr($word, 16)];
		} elseif (str_starts_with($word, '-c') && strlen($word) > 2) {
			$configuration = ['-c', substr($word, 2)];
		} elseif (in_array(substr((string) strrchr('/' . $word, '/'), 1), ['phpstan', 'phpstan.phar'], true)) {
			$binary = $word;
		}
	}
	$binary ??= find_phpstan_binary();
	if ($binary === null) {
		return null;
	}
	return array_merge([$binary, 'dump-parameters', '--json'], $configuration);
}

// --- Paths -------------------------------------------------------------------

/**
 * Resolve the path from a unified-diff "+++ " file header to a repo-relative path.
 *
 * Handles a trailing tab-separated timestamp, C-style quoting (as git uses for
 * unusual characters) and the "b/" prefix.
 *
 * @phpstan-pure
 * @param string $path What follows "+++ " in the header.
 * @return string|null The repo-relative path, or null when the new side is /dev/null (a
 *                     deleted file).
 */
function parse_header_path(string $path): ?string
{
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
 * Express a path relative to a directory when it's inside it.
 *
 * @phpstan-pure
 * @param string $path The path.
 * @param string $base The directory, with or without a trailing "/".
 * @return string The path relative to $base, or $path unchanged if it's not inside $base.
 */
function relative_to(string $path, string $base): string
{
	$base = rtrim($base, '/');
	if ($base !== '' && str_starts_with($path, $base . '/')) {
		return substr($path, strlen($base) + 1);
	}
	return $path;
}

/**
 * Express a repo-relative file path relative to the current directory.
 *
 * @phpstan-pure
 * @param non-empty-string $file   The repo-relative path.
 * @param string           $prefix The current directory, as given by git's `rev-parse
 *                                 --show-prefix` (e.g. "src/" in the repo's src/, or ''
 *                                 at the top level).
 * @return string The path relative to the current directory (e.g. "../a.php").
 */
function relative_to_prefix(string $file, string $prefix): string
{
	$prefixParts = $prefix === '' ? [] : explode('/', rtrim($prefix, '/'));
	$fileParts   = explode('/', $file);
	$common      = 0; // How many leading directories they share.
	while (
		$common < count($prefixParts)
		&& $common < count($fileParts) - 1
		&& $prefixParts[$common] === $fileParts[$common]
	) {
		$common++;
	}
	return str_repeat('../', count($prefixParts) - $common) . implode('/', array_slice($fileParts, $common));
}

/**
 * Whether a repo-relative file path matches an --include/--exclude pattern.
 *
 * @param string $file    The repo-relative path.
 * @param string $pattern An exact file, a directory (matching everything under it; a
 *                        trailing "/" is optional), or a glob (where "*" may span "/").
 * @return bool Whether it matches.
 */
function path_matches(string $file, string $pattern): bool
{
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
 * Whether a repo-relative file path is kept by --include and --exclude.
 *
 * @see path_matches()
 *
 * @param string       $file     The repo-relative path.
 * @param list<string> $includes Patterns of which it must match one (unless there are none).
 * @param list<string> $excludes Patterns of which it mustn't match any (exclude wins).
 * @return bool Whether it's kept.
 */
function path_selected(string $file, array $includes, array $excludes): bool
{
	if ($includes !== []) {
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
 * Normalize an absolute path the way PHPStan's FileHelper does.
 *
 * Collapses "//" and "/./" and resolves "..", but doesn't resolve symlinks.
 *
 * @phpstan-pure
 * @param string $path An absolute path.
 * @return non-falsy-string The normalized path, starting with "/" and without a trailing one
 *                          (unless it's the root).
 */
function normalize_path(string $path): string
{
	$parts = [];
	foreach (explode('/', $path) as $part) {
		if ($part === '' || $part === '.') {
			continue;
		}
		if ($part === '..') {
			$parts = array_slice($parts, 0, -1);
			continue;
		}
		$parts[] = $part;
	}
	return '/' . implode('/', $parts);
}

/**
 * Read the analyse excludes (excludePaths.analyse and analyseAndScan) from
 * PHPStan's resolved parameters.
 *
 * @param string $raw The output of `phpstan dump-parameters --json`.
 * @return list<non-empty-string>|null The excluded paths and patterns, or null if $raw can't
 *                                     be parsed.
 */
function read_analyse_excludes(string $raw): ?array
{
	if ($raw === '') {
		return null;
	}
	// PHPStan prints the dump through Symfony Console, which unescapes "\<" to "<"
	// and so leaves invalid escapes behind (e.g. a regex's "\\<" becomes "\<").
	// Re-double any backslash that doesn't start a valid JSON escape.
	$raw = (string) preg_replace_callback(
		'/\\\\(.)/s',
		static fn(array $matches): string => str_contains('"\\/bfnrtu', $matches[1])
			? $matches[0]
			: '\\\\' . $matches[1],
		$raw
	);
	$parameters = json_decode($raw, true);
	if (!is_array($parameters) || !isset($parameters['excludePaths']) || !is_array($parameters['excludePaths'])) {
		return null;
	}
	$excludes = [];
	foreach (['analyse', 'analyseAndScan'] as $kind) {
		foreach ((array) ($parameters['excludePaths'][$kind] ?? []) as $exclude) {
			if (!is_string($exclude) || $exclude === '') {
				continue;
			}

			$excludes[] = $exclude;
		}
	}
	return $excludes;
}

/**
 * Whether PHPStan would exclude a file from analysis.
 *
 * Mirrors PHPStan\File\FileExcluder::isExcludedFromAnalysing(): an exclude is an
 * fnmatch() pattern if it contains any of *?[], else an exact file or a directory
 * (entries that exist as neither are ignored).
 *
 * @param string                 $file     The file's absolute path.
 * @param list<non-empty-string> $excludes As from read_analyse_excludes().
 * @return bool Whether it's excluded.
 */
function is_excluded_by_phpstan(string $file, array $excludes): bool
{
	$file = normalize_path($file);
	foreach ($excludes as $exclude) {
		$trailingSeparator = str_ends_with($exclude, '/');
		$normalized        = normalize_path($exclude) . ($trailingSeparator ? '/' : '');
		if (preg_match('~[*?[\]]~', $normalized) === 1) {
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
 * Find the remote-tracking counterparts of a branch that are worth considering as
 * a base.
 *
 * Those are the local branch's configured upstream, then the branch on the
 * conventional "origin" and "upstream" remotes, where they exist. Other remotes (a
 * collaborator's fork, say) are left out, since their branch may well have merged
 * this branch's own commits.
 *
 * @param non-empty-string $branch The branch's short name (e.g. "trunk").
 * @return list<non-falsy-string> Their full refs (e.g. "refs/remotes/origin/trunk").
 */
function remote_counterparts(string $branch): array
{
	$counterparts = [];
	$upstream     = '';
	if (git_succeeds(['show-ref', '--verify', '--quiet', "refs/heads/$branch"])) {
		$upstream = git_line(['for-each-ref', '--format=%(upstream)', "refs/heads/$branch"]);
		// (An upstream outside refs/remotes/ is another local branch.)
		if (
			str_starts_with($upstream, 'refs/remotes/')
			&& git_succeeds(['show-ref', '--verify', '--quiet', $upstream])
		) {
			$counterparts[] = $upstream;
		}
	}
	$remotes = git(['remote']);
	foreach (['origin', 'upstream'] as $remote) {
		$ref = "refs/remotes/$remote/$branch";
		if (
			!in_array($remote, $remotes, true)
			|| $ref === $upstream
			|| !git_succeeds(['show-ref', '--verify', '--quiet', $ref])
		) {
			continue;
		}

		$counterparts[] = $ref;
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
 * Exits with a fatal error if $base is neither a commit nor a branch on a remote.
 *
 * @param non-empty-string $base The base asked for (a branch, or any commit-ish).
 * @return array{used: non-empty-list<array{ref: non-empty-string, commit: string}>, note: string}
 *         The refs the diff is taken from (the first name wins where several
 *         share a merge-base), each with the commit to diff against, and why
 *         they differ from the requested one ('' if they don't).
 */
function select_bases(string $base): array
{
	$isLocalBranch = git_succeeds(['show-ref', '--verify', '--quiet', "refs/heads/$base"]);
	$isCommit      = git_succeeds(['rev-parse', '--verify', '--quiet', "$base^{commit}"]);

	$counterparts = [];
	if ($isLocalBranch || (!$isCommit && git_succeeds(['check-ref-format', '--branch', $base]))) {
		$counterparts = remote_counterparts($base);
	}

	// Candidates: the requested base itself (unless it's a branch that only exists
	// on remotes), then its remote-tracking counterparts. Full refs avoid
	// ambiguity, e.g. with a tag of the same name.
	$candidates   = []; // list of array{name: string, ref: string}
	$requestedRef = '';
	if ($isLocalBranch) {
		$requestedRef = "refs/heads/$base";
	} elseif ($counterparts === []) {
		$requestedRef = $base;
	}
	if ($requestedRef !== '' && git_succeeds(['rev-parse', '--verify', '--quiet', "$requestedRef^{commit}"])) {
		$candidates[] = ['name' => $base, 'ref' => $requestedRef];
	}
	foreach ($counterparts as $ref) {
		$name = substr($ref, strlen('refs/remotes/')); // E.g. "origin/trunk".
		if ($name === '') {
			continue;
		}

		$candidates[] = ['name' => $name, 'ref' => $ref];
	}
	if ($candidates === []) {
		fail("base '$base' is not a valid commit or branch (see --base).");
	}

	$headCommit         = git_line(['rev-parse', '--verify', '--quiet', 'HEAD']);
	$headBranch         = git_line(['symbolic-ref', '--quiet', 'HEAD']);
	$hasOlderMergeBase  = false;
	$requestedMergeBase = '';
	foreach ($candidates as $index => $candidate) {
		$mergeBase                         = git_line(['merge-base', $candidate['ref'], 'HEAD']);
		$candidates[$index]['merge_base'] = $mergeBase;
		if ($mergeBase !== '' && $mergeBase !== $headCommit) {
			$hasOlderMergeBase = true;
		}
		if ($candidate['ref'] !== $requestedRef) {
			continue;
		}

		$requestedMergeBase = $mergeBase;
	}

	$mergeBases = []; // commit => name
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
	$used = [];
	if ($mergeBases !== []) {
		$independent = git(array_merge(['merge-base', '--independent'], array_map('strval', array_keys($mergeBases))));
		foreach ($mergeBases as $commit => $name) {
			if (!in_array($commit, $independent, true)) {
				continue;
			}

			$used[] = ['ref' => $name, 'commit' => $commit];
		}
	}
	if ($used === []) {
		// No merge-base at all (unrelated histories, or a shallow clone whose
		// history doesn't reach back far enough): diff against the base itself.
		$used[] = [
			'ref'    => $candidates[0]['name'],
			'commit' => git_line(['rev-parse', '--verify', '--quiet', $candidates[0]['ref'] . '^{commit}']),
		];
	}

	$usedNames = implode(' + ', array_column($used, 'ref')); // E.g. "upstream/trunk + origin/trunk".
	$note      = '';
	if (count($used) > 1) {
		$note = "This branch merged $usedNames, whose histories have diverged,"
			. ' so only lines changed relative to all of them count.';
	} elseif ($usedNames !== $base && $requestedRef !== '') {
		// (A base that only exists on remotes, as in CI, needs no note.)
		if ($requestedMergeBase === '') {
			$note = "The local $base shares no history with this branch, so the diff is against $usedNames instead.";
		} elseif ($requestedMergeBase === $headCommit) {
			$note = "The local $base already contains this branch, so the diff is against $usedNames instead.";
		} else {
			$note = "This branch contains commits from $usedNames that the local $base lacks,"
				. " so the diff is against $usedNames instead.";
		}
	}
	if ($note !== '' && $isLocalBranch) {
		$note = rtrim($note, '.') . " (to use $base as-is, pass --base=refs/heads/$base).";
	}
	if ($mergeBases === [] && git_line(['rev-parse', '--is-shallow-repository']) === 'true') {
		// Then the diff is against the base's tip, so its changes since this branch
		// diverged count as changed lines too.
		$name = $candidates[0]['name'];
		$note = "This is a shallow clone, and its history doesn't reach where this branch and $name diverged,"
			. " so the diff is against $name itself and includes its newer changes."
			. ' Fetch more history (e.g. git fetch --unshallow, or fetch-depth: 0 with actions/checkout).';
	}

	return ['used' => $used, 'note' => $note];
}

// --- Changed lines -----------------------------------------------------------

/**
 * Find the .php files (added, copied, modified or renamed) that differ from every
 * one of the given commits.
 *
 * @param non-empty-list<string> $targets The commits to diff against.
 * @param bool                   $staged  Whether to diff the index rather than the working tree.
 * @return list<non-empty-string> The files' paths, relative to the top level (wherever this
 *                                is run from).
 */
function changed_php_files(array $targets, bool $staged): array
{
	$files = null;
	foreach ($targets as $target) {
		$arguments = ['git', 'diff', '-z', '--name-only', '--diff-filter=ACMR'];
		if ($staged) {
			$arguments[] = '--cached';
		}
		// (":(top)" so that, from a subdirectory, the whole repository is
		// still covered, as with a plain `git diff`.)
		array_push($arguments, $target, '--', ':(top)*.php');
		$output      = rtrim(run($arguments)['stdout'], "\0");
		$targetFiles = array_values(
			array_filter(explode("\0", $output), static fn(string $file): bool => $file !== ''),
		);
		$files       = $files === null ? $targetFiles : array_values(array_intersect($files, $targetFiles));
	}
	return $files;
}

/**
 * Find the lines that differ from a commit, by running `git diff` against it.
 *
 * Exits with a fatal error if `git diff` fails.
 *
 * @param string           $target   The commit to diff against.
 * @param bool             $staged   Whether to diff the index rather than the working tree.
 * @param list<string>     $paths    Paths to restrict the diff to (none for the whole repository).
 * @param non-empty-string $topLevel The repository's top-level directory.
 * @return array<string, list<array{int, int, string}>> Absolute path => the new-side line
 *         ranges, as inclusive [start, end, added text] per hunk, in ascending order. (Files
 *         with no hunks that add lines are left out.)
 */
function changed_ranges(string $target, bool $staged, array $paths, string $topLevel): array
{
	$diffArguments = ['diff', '--no-color', '--unified=0', '--src-prefix=a/', '--dst-prefix=b/'];
	if ($staged) {
		// Compare the index (staged snapshot) rather than the working tree, so
		// unstaged edits are excluded. The base ref stays the merge-base, so
		// committed branch changes are still in scope; pass --base=HEAD to narrow
		// this down to staged changes only.
		$diffArguments[] = '--cached';
	}
	$diffArguments[] = $target;
	if ($paths !== []) {
		$diffArguments[] = '--';
		foreach ($paths as $path) {
			$diffArguments[] = $path;
		}
	}
	$diffOutput = git($diffArguments, $diffStatus);
	if ($diffStatus !== 0) {
		fail("`git diff` failed — is " . var_export($target, true) . " a valid commit?");
	}

	$changed     = []; // abs-path => list of [start, end] per hunk
	/** @var array<string, array<int, string>> $addedText abs-path => hunk index => the lines it adds */
	$addedText   = [];
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
					$currentFile = $relativePath;
					if ($relativePath !== '' && $relativePath[0] !== '/') {
						$currentFile = $topLevel . '/' . $relativePath;
					}
					if (!isset($changed[$currentFile])) {
						$changed[$currentFile] = [];
					}
				}
				continue;
			}
			if (!str_starts_with($line, '@@ ')) {
				continue; // index / mode / rename / "--- a/..." header lines
			}

			$inHeader = false; // fall through to hunk handling
		}
		if ($currentFile !== null && str_starts_with($line, '@@ ')) {
			$inHunk = false;
			if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches) === 1) {
				$start = (int) $matches[1];
				$count = isset($matches[2]) ? (int) $matches[2] : 1;
				if ($count > 0) {
					$changed[$currentFile][] = [$start, $start + $count - 1];
					$hunkIndex               = count($changed[$currentFile]) - 1;
					$inHunk                  = true;
				}
			}
			continue;
		}
		// Keep each hunk's added lines, so combine_ranges() can recognize a hunk
		// that git placed differently in two diffs. ("-" lines are ignored.)
		if (!$inHunk || $currentFile === null || !str_starts_with($line, '+')) {
			continue;
		}

		$addedText[$currentFile][$hunkIndex] = ($addedText[$currentFile][$hunkIndex] ?? '') . substr($line, 1) . "\n";
	}
	// (Files with no hunks that add lines, i.e. pure deletions, mode changes and
	// pure renames, are left out.)
	$ranges = [];
	foreach ($changed as $file => $hunks) {
		foreach ($hunks as $index => [$start, $end]) {
			$ranges[$file][] = [$start, $end, $addedText[$file][$index] ?? ''];
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
 * @param list<array{int, int, string}> $first  Ascending, inclusive [start, end, added text]
 *                                              ranges, as from changed_ranges().
 * @param list<array{int, int, string}> $second Likewise, for the other merge-base.
 * @return list<array{int, int, string}> The combined ranges, ascending and merged where they
 *         overlap or touch. (The added text of a combined range is only kept for a slid hunk.)
 */
function combine_ranges(array $first, array $second): array
{
	$kept = [];
	foreach ($first as $firstRange) {
		foreach ($second as $secondRange) {
			if ($secondRange[1] < $firstRange[0] || $secondRange[0] > $firstRange[1]) {
				continue; // No overlap.
			}
			if ($firstRange[2] !== '' && $firstRange[2] === $secondRange[2]) {
				$kept[] = [min($firstRange[0], $secondRange[0]), max($firstRange[1], $secondRange[1]), $firstRange[2]];
			} else {
				$kept[] = [max($firstRange[0], $secondRange[0]), min($firstRange[1], $secondRange[1]), ''];
			}
		}
	}
	// Sort and merge overlapping or adjacent ranges.
	usort($kept, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
	$result = [];
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
 * Wrap text in an ANSI SGR sequence when color is enabled.
 *
 * @phpstan-pure
 * @param string           $text    The text.
 * @param non-empty-string $codes   The SGR parameters, e.g. "1;37;41" for bold white on red.
 * @param bool             $enabled Whether color is enabled.
 * @return string The styled text, or $text unchanged.
 */
function style(string $text, string $codes, bool $enabled): string
{
	return $enabled ? "\033[{$codes}m$text\033[0m" : $text;
}

/**
 * Find the top-level directory of the repository this is run in.
 *
 * Exits with a fatal error outside a git repository.
 *
 * @return non-empty-string Its absolute path.
 */
function top_level(): string
{
	$topLevel = git_line(['rev-parse', '--show-toplevel']);
	if ($topLevel === '') {
		fail('not inside a git repository');
	}
	return $topLevel;
}

/**
 * Decode a PHPStan JSON report.
 *
 * Exits with a fatal error if it's JSON but its "files" isn't an object.
 *
 * @param string $json The output of `phpstan analyse --error-format=json`.
 * @return array{
 *     files: array<string, list<array<array-key, mixed>>>,
 *     errors: list<mixed>,
 *     other: array<array-key, mixed>,
 * }|null
 *         Each file's messages, by absolute path; the errors not tied to a file;
 *         and any other keys of the report, which are passed through to JSON
 *         output. (The totals are left out, being recounted after filtering.)
 *         Null if $json isn't a JSON object.
 */
function decode_report(string $json): ?array
{
	$report = json_decode($json, true);
	if (!is_array($report)) {
		return null;
	}
	$files = $report['files'] ?? [];
	if (!is_array($files)) {
		fail('unexpected PHPStan JSON shape: "files" is not an object');
	}
	$decoded = empty_report();
	foreach ($files as $path => $data) {
		$messages = is_array($data) && isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : [];
		$decoded['files'][(string) $path] = array_values(array_filter($messages, 'is_array'));
	}
	$errors            = $report['errors'] ?? [];
	$decoded['errors'] = is_array($errors) ? array_values($errors) : [];
	unset($report['totals'], $report['files'], $report['errors']);
	$decoded['other'] = $report;
	return $decoded;
}

/**
 * A PHPStan report with no errors, in the form decode_report() returns.
 *
 * @phpstan-pure
 * @return array{
 *     files: array<string, list<array<array-key, mixed>>>,
 *     errors: list<mixed>,
 *     other: array<array-key, mixed>,
 * }
 *         The empty report.
 */
function empty_report(): array
{
	return ['files' => [], 'errors' => [], 'other' => []];
}

/**
 * Read a field of a PHPStan message as a string.
 *
 * @phpstan-pure
 * @param array<array-key, mixed>     $message A message from a PHPStan JSON report.
 * @param non-empty-string $key     The field, e.g. "message", "line", "tip" or "identifier".
 * @return string Its value, or '' if it's missing or isn't a string or number.
 */
function message_text(array $message, string $key): string
{
	$value = $message[$key] ?? '';
	return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
}

/**
 * Keep only the messages of a PHPStan report that fall on changed lines, and
 * print them, as a PHPStan-style table or as JSON.
 *
 * Errors not tied to a file are always kept. Exits with a fatal error outside a
 * git repository, or if `git diff` fails.
 *
 * @param array{
 *     files: array<string, list<array<array-key, mixed>>>,
 *     errors: list<mixed>,
 *     other: array<array-key, mixed>,
 * } $report
 *     As from decode_report().
 * @param array{
 *     base: non-empty-string,
 *     usedBases?: list<array{ref: non-empty-string, commit: string}>,
 *     baseNote?: string,
 *     staged?: bool,
 *     format?: 'table'|'json',
 *     paths?: list<string>,
 *     includes?: list<string>,
 *     excludes?: list<string>,
 *     nothingAnalysed?: 'no-changed-files'|'excluded-by-config'|null,
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
 * @return int EXIT_ERRORS if any errors remain, else EXIT_OK.
 * @phpstan-return 0|1
 */
function filter_report(array $report, array $options): int
{
	$base              = $options['base'];
	$usedBases         = $options['usedBases'] ?? [];
	$baseNote          = $options['baseNote'] ?? '';
	$staged            = $options['staged'] ?? false;
	$format            = $options['format'] ?? 'table';
	$paths             = $options['paths'] ?? [];
	$includes          = $options['includes'] ?? [];
	$excludes          = $options['excludes'] ?? [];
	$nothingAnalysed   = $options['nothingAnalysed'] ?? null;
	$phpstanParameters = $options['phpstanParameters'] ?? null;

	$topLevel = top_level();

	// The commits to diff against: the bases chosen already, else the merge-base
	// with <base>, else <base> itself. With several (merge-bases of bases whose
	// histories diverged), a line counts as changed only if it changed relative to
	// every one of them, so commits that came from any one base don't count.
	if ($usedBases === []) {
		$commit = git_line(['merge-base', $base, 'HEAD']);
		if ($commit === '') {
			$commit = git_line(['rev-parse', '--verify', '--quiet', $base . '^{commit}']);
			$commit = $commit !== '' ? $commit : $base;
		}
		$usedBases[] = ['ref' => $base, 'commit' => $commit];
	}
	$diffTargets = array_column($usedBases, 'commit');

	/**
	 * Absolute path => the inclusive [start, end, added text] new-side ranges.
	 *
	 * @var array<string, list<array{int, int, string}>>|null $changed
	 */
	$changed = null;
	foreach ($diffTargets as $diffTarget) {
		$targetChanged = changed_ranges($diffTarget, $staged, $paths, $topLevel);
		if ($changed === null) {
			$changed = $targetChanged;
			continue;
		}
		foreach ($changed as $file => $ranges) {
			$ranges = isset($targetChanged[$file]) ? combine_ranges($ranges, $targetChanged[$file]) : [];
			if ($ranges !== []) {
				$changed[$file] = $ranges;
			} else {
				unset($changed[$file]);
			}
		}
	}

	// --- Filter the report ---

	$keptFiles = [];
	$keptCount = 0;
	foreach ($report['files'] as $absolutePath => $messages) {
		$ranges = $changed[$absolutePath] ?? null;
		if ($ranges === null) {
			$resolvedPath = @realpath($absolutePath);
			if ($resolvedPath !== false && isset($changed[$resolvedPath])) {
				$ranges = $changed[$resolvedPath];
			}
		}
		if ($ranges === null) {
			continue;
		}
		if ($includes !== [] || $excludes !== []) {
			$relativePath = relative_to($absolutePath, $topLevel);
			if ($relativePath === $absolutePath) {
				// Key wasn't under the repo root (e.g. a symlinked path); resolve it,
				// mirroring the realpath fallback used for the changed-line lookup.
				$resolvedPath = @realpath($absolutePath);
				if ($resolvedPath !== false) {
					$relativePath = relative_to($resolvedPath, $topLevel);
				}
			}
			if (!path_selected($relativePath, $includes, $excludes)) {
				continue;
			}
		}
		$kept = [];
		foreach ($messages as $message) {
			$lineNumber = $message['line'] ?? null;
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
		if ($kept === []) {
			continue;
		}
		$keptFiles[$absolutePath] = $kept;
		$keptCount += count($kept);
	}

	$genericErrors = $report['errors'];
	$hasErrors     = ($keptCount > 0 || count($genericErrors) > 0);

	// --- Output ---

	// Which of the files handed to PHPStan its own configuration excluded, if known.
	$excludedPaths = [];
	if ($nothingAnalysed === 'excluded-by-config') {
		$excludedPaths = $paths;
	} elseif ($nothingAnalysed === null && $phpstanParameters !== null && $paths !== []) {
		$analyseExcludes = read_analyse_excludes($phpstanParameters);
		if ($analyseExcludes !== null && $analyseExcludes !== []) {
			$workingDirectory = getcwd();
			$workingDirectory = $workingDirectory !== false ? $workingDirectory : $topLevel;
			foreach ($paths as $path) {
				$absolutePath = str_starts_with($path, '/') ? $path : $workingDirectory . '/' . $path;
				if (!is_file($absolutePath) || !is_excluded_by_phpstan($absolutePath, $analyseExcludes)) {
					continue;
				}

				$excludedPaths[] = $path;
			}
		}
	}

	// How the base was chosen (for the summary line and a note).
	// E.g. "upstream/trunk @ 1a2b3c4", or "upstream/trunk + origin/trunk @ 1a2b3c4 + 5d6e7f8".
	$shortCommits = [];
	foreach ($diffTargets as $diffTarget) {
		$shortCommit    = git_line(['rev-parse', '--short', $diffTarget]);
		$shortCommits[] = $shortCommit !== '' ? $shortCommit : $diffTarget;
	}
	$baseLabel = implode(' + ', array_column($usedBases, 'ref'))
		. ' @ ' . implode(' + ', $shortCommits)
		. ($staged ? ', staged' : '');

	// Why some or all of the files in scope weren't analysed.
	$note      = '';
	$fileCount = count($paths);
	if ($nothingAnalysed === 'no-changed-files') {
		$note = 'No changed .php files in scope, so nothing was analysed.';
	} elseif ($excludedPaths !== []) {
		$excludedCount = count($excludedPaths);
		if ($excludedCount === $fileCount) {
			$note = 'PHPStan\'s configuration (excludePaths) excludes '
				. ($fileCount === 1 ? 'the only file' : 'all ' . $fileCount . ' files')
				. ' in scope, so nothing was analysed:';
		} else {
			$note = 'PHPStan\'s configuration (excludePaths) excludes '
				. $excludedCount . ' of the ' . $fileCount . ' files in scope, so '
				. ($excludedCount === 1 ? 'it was' : 'they were') . ' not analysed:';
		}
		foreach ($excludedPaths as $path) {
			$note .= "\n  " . $path;
		}
	}

	if ($format === 'json') {
		foreach ([$baseNote, $note] as $stderrNote) {
			if ($stderrNote === '') {
				continue;
			}

			fwrite(STDERR, program() . ": $stderrNote\n"); // Keep stdout pure JSON.
		}
		$files = array_map(
			static fn(array $messages): array => ['errors' => count($messages), 'messages' => $messages],
			$keptFiles,
		);
		$document                 = [
			'totals' => ['errors' => count($genericErrors), 'file_errors' => $keptCount],
			'files'  => $files !== [] ? $files : new stdClass(),
			'errors' => $genericErrors,
		] + $report['other'];
		$document['phpstan_diff'] = [
			'base' => [
				'requested' => $base,
				// Each ref the diff is taken from, with the commit diffed against: its
				// merge-base with HEAD, or the ref itself if it shares no history with
				// HEAD. With several, a line counts as changed only if it changed relative
				// to every one of them.
				'used'      => $usedBases,
				'staged'    => $staged,
			],
		];
		echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
		return $hasErrors ? EXIT_ERRORS : EXIT_OK;
	}

	// Table format.
	$useColor = (getenv('NO_COLOR') === false) && function_exists('stream_isatty') && @stream_isatty(STDOUT);

	$columns = (int) getenv('COLUMNS');
	if ($columns <= 0) {
		$ttySize = @shell_exec('{ stty size < /dev/tty ; } 2>/dev/null');
		if (is_string($ttySize) && preg_match('/^\d+\s+(\d+)/', trim($ttySize), $matches) === 1) {
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

	if ($keptFiles !== []) {
		$lineColumnWidth = 4; // strlen('Line')
		foreach ($keptFiles as $messages) {
			foreach ($messages as $message) {
				$lineColumnWidth = max($lineColumnWidth, strlen(message_text($message, 'line')));
			}
		}
		$indent             = str_repeat(' ', $lineColumnWidth + 5); // "  " + line col + "   "
		$messageColumnWidth = max(20, $columns - $lineColumnWidth - 6);
		$rule               = ' ' . str_repeat('-', $lineColumnWidth + 2)
			. ' ' . str_repeat('-', $messageColumnWidth + 2) . "\n";

		foreach ($keptFiles as $absolutePath => $messages) {
			$displayPath = relative_to($absolutePath, $workingDirectory);
			$output .= $rule;
			$output .= '  ' . str_pad('Line', $lineColumnWidth) . '   ' . style($displayPath, '1', $useColor) . "\n";
			$output .= $rule;
			foreach ($messages as $message) {
				$lineNumberText = message_text($message, 'line');
				$text           = message_text($message, 'message');
				$segments       = explode("\n", wordwrap($text, $messageColumnWidth, "\n", true));
				$first          = true;
				foreach ($segments as $segment) {
					$left = $first
						? style(str_pad($lineNumberText, $lineColumnWidth), '32', $useColor)
						: str_repeat(' ', $lineColumnWidth);
					$output .= '  ' . $left . '   ' . $segment . "\n";
					$first   = false;
				}
				$tip = message_text($message, 'tip');
				if ($tip !== '') {
					foreach (explode("\n", wordwrap('💡 ' . $tip, $messageColumnWidth, "\n", true)) as $segment) {
						$output .= $indent . style($segment, '36', $useColor) . "\n";
					}
				}
				$identifier = message_text($message, 'identifier');
				if ($identifier === '') {
					continue;
				}

				$output .= $indent . style('🪪  ' . $identifier, '2', $useColor) . "\n";
			}
			$output .= $rule;
			$output .= "\n";
		}
	}

	if ($genericErrors !== []) {
		foreach ($genericErrors as $error) {
			$text = is_string($error) ? $error : (string) json_encode($error);
			$output .= ' ' . style('✗', '31', $useColor) . ' ' . $text . "\n";
		}
		$output .= "\n";
	}

	// The base note is prose, so wrap it; the other note may list file paths, which
	// must stay one per line.
	foreach ([wordwrap($baseNote, $columns - 2), $note] as $tableNote) {
		if ($tableNote === '') {
			continue;
		}

		$output .= style(' ' . str_replace("\n", "\n ", $tableNote), '2', $useColor) . "\n\n";
	}

	if ($hasErrors) {
		$total = $keptCount + count($genericErrors);
		$label = ' [ERROR] Found ' . $total . ' error' . ($total === 1 ? '' : 's')
			. ' on changed lines (base: ' . $baseLabel . ') ';
		$output .= style($label, '1;37;41', $useColor) . "\n\n";
	} else {
		$output .= style(' [OK] No errors on changed lines (base: ' . $baseLabel . ') ', '1;37;42', $useColor) . "\n\n";
	}

	echo $output;
	return $hasErrors ? EXIT_ERRORS : EXIT_OK;
}

// --- bin/phpstan-diff --------------------------------------------------------

/**
 * Run bin/phpstan-diff: parse its command line, choose the base, run PHPStan on
 * the changed files and print the errors on the changed lines.
 *
 * Exits with a fatal error on bad usage, outside a git repository, or if
 * PHPStan can't be found.
 *
 * @param list<string>     $arguments The command-line arguments, without the script's name.
 * @param non-empty-string $usageFile The script whose docblock is printed for --help.
 * @return int EXIT_OK, EXIT_ERRORS or EXIT_INTERNAL.
 * @phpstan-return 0|1|2
 */
function main(array $arguments, string $usageFile): int
{
	$base         = getenv('PHPSTAN_DIFF_BASE');
	$base         = is_string($base) && $base !== '' ? $base : 'trunk';
	$staged       = false;
	$wholeProject = false;
	$format       = 'table';
	$paths        = [];
	$includes     = [];
	$excludes     = [];

	$argumentCount = count($arguments);
	for ($index = 0; $index < $argumentCount; $index++) {
		$argument = $arguments[$index];
		if ($argument === '--') {
			array_push($paths, ...array_slice($arguments, $index + 1));
			break;
		}
		if (preg_match('/^--(base|include|exclude|format)(?:=(.*))?$/s', $argument, $matches) === 1) {
			if (!isset($matches[2])) {
				if (!isset($arguments[$index + 1])) {
					fail("--$matches[1] requires a value");
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
			continue; // Deprecated: analysing only the changed files is now the default.
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
	if ($base === '') {
		fail('--base must not be empty');
	}

	top_level(); // Fails outside a git repository.
	$analyseCommand = resolve_analyse_command();
	$bases          = select_bases($base);
	$filterOptions  = [
		'base'      => $base,
		'usedBases' => $bases['used'],
		'baseNote'  => $bases['note'],
		'staged'    => $staged,
		'format'    => $format,
		'includes'  => $includes,
		'excludes'  => $excludes,
	];

	// By default, analyse only the changed .php files that --include/--exclude
	// keep (so PHPStan never analyses the others, rather than their errors being
	// hidden later). With several merge-bases, a file is in scope only if it
	// changed relative to all of them.
	if (!$wholeProject && $paths === []) {
		$prefix = git_line(['rev-parse', '--show-prefix']);
		foreach (changed_php_files(array_column($bases['used'], 'commit'), $staged) as $file) {
			if (!path_selected($file, $includes, $excludes)) {
				continue;
			}

			$paths[] = relative_to_prefix($file, $prefix);
		}
		if ($paths === []) {
			return filter_report(empty_report(), $filterOptions + ['nothingAnalysed' => 'no-changed-files']);
		}
	}
	$filterOptions['paths'] = $paths;

	// When PHPStan is handed specific files, it silently skips any its
	// excludePaths cover. Dump its resolved parameters alongside the analysis
	// (concurrently, since it boots PHPStan too) so the filter can name the
	// skipped files.
	$commands = [
		'analyse' => array_merge(
			$analyseCommand,
			['--error-format=json', '--no-progress'],
			$paths !== [] ? array_merge(['--'], $paths) : [],
		),
	];
	$dumpCommand = $paths !== [] ? resolve_dump_command($analyseCommand) : null;
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
		$nothingFound = str_contains($stderr, 'No files found to analyse')
			|| str_contains($stdout, 'No files found to analyse');
		if ($paths !== [] && $nothingFound) {
			return filter_report(empty_report(), $filterOptions + ['nothingAnalysed' => 'excluded-by-config']);
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
