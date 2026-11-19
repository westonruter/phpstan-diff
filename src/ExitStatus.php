<?php

/**
 * The ExitStatus enum.
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
 * What phpstan-diff and phpstan-diff-filter exit with.
 */
enum ExitStatus: int
{
	/**
	 * No errors on the changed lines.
	 */
	case Ok = 0;

	/**
	 * Errors remain on the changed lines.
	 */
	case Errors = 1;

	/**
	 * Something went wrong (bad usage, git or PHPStan failing, ...).
	 */
	case Internal = 2;
}
