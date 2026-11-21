<?php

/**
 * Load the code behind bin/phpstan-diff and bin/phpstan-diff-filter, all in the
 * WestonRuter\PHPStanDiff namespace. (The scripts don't rely on a Composer
 * autoloader, since they're run from a checkout, a symlink to one, or Composer's
 * vendor/bin proxy, none of which needs one.)
 *
 * @package   WestonRuter\PHPStanDiff
 * @author    Weston Ruter
 * @copyright 2026 Weston Ruter
 * @license   https://opensource.org/license/mit MIT
 * @link      https://github.com/westonruter/phpstan-diff
 */

declare(strict_types=1);

require_once __DIR__ . '/ExitStatus.php';
require_once __DIR__ . '/Format.php';
require_once __DIR__ . '/NothingAnalyzed.php';
require_once __DIR__ . '/phpstan-diff.php';
