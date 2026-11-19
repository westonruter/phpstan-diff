<?php

/**
 * The Format enum.
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
 * How the filtered report is printed (--format).
 */
enum Format: string
{
	/**
	 * A human-readable table, like `phpstan analyse` prints.
	 */
	case Table = 'table';

	/**
	 * The filtered PHPStan JSON document.
	 */
	case Json = 'json';
}
