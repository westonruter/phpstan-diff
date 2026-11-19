<?php

/**
 * The NothingAnalyzed enum.
 *
 * @package   WestonRuter\PHPStanDiff
 * @author    Weston Ruter
 * @copyright 2026 Weston Ruter
 * @license   https://opensource.org/license/mit MIT
 * @link      https://github.com/westonruter/phpstan-diff
 */

declare(strict_types=1);

namespace WestonRuter\PHPStanDiff;

/**
 * Why PHPStan analysed nothing, for a note with the empty report.
 */
enum NothingAnalyzed
{
	/**
	 * No .php files in scope had changed, so PHPStan wasn't run.
	 */
	case NoChangedFiles;

	/**
	 * PHPStan's configuration (excludePaths) excluded every file it was given.
	 */
	case ExcludedByConfig;
}
