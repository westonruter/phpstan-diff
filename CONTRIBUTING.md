# Contributing

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

To run them against the PHARs instead of the scripts in the checkout:

```bash
tools/build-phar
PHPSTAN_DIFF=build/phpstan-diff.phar PHPSTAN_DIFF_FILTER=build/phpstan-diff-filter.phar tests/run-tests.sh
```

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

The shell scripts are checked with [ShellCheck](https://www.shellcheck.net/):

```bash
shellcheck tests/run-tests.sh tools/release
```

The tests (against both the scripts and the PHARs), PHPStan, PHPCS and ShellCheck
all run in CI, and must pass for a pull request to be merged.

## Releasing

Versions follow [semver](https://semver.org/), and come from git tags alone: there's
no version number in `composer.json` or in the code to bump. The release workflow
([`release.yml`](.github/workflows/release.yml), described in
[`.github/workflows/README.md`](.github/workflows/README.md#releaseyml)) builds the
PHARs and attaches them to a draft release, which is then published by hand. As the
repository has immutable releases turned on, a published release's tag and files
can't be changed.

1. With `main` checked out, run:

   ```sh
   composer release 1.2.3
   ```

   [`tools/release`](tools/release) checks that the version is of the form X.Y.Z
   and newer than the latest release, and that `main` is clean and the same as
   GitHub's once pulled. Then, once you confirm, it makes a signed `v1.2.3` tag and
   pushes it, which starts the release workflow. Composer users can install the
   new version as soon as Packagist sees the tag.
2. When the workflow has finished, open the draft from the
   [releases](https://github.com/westonruter/phpstan-diff/releases) (the run's
   summary links to it). Its notes are generated from the pull requests merged
   since the previous tag.
3. Edit the notes, and publish it. Subscribers are notified with the notes it's
   published with, and its files and tag can't be changed after that.

Don't create the release from the web interface before pushing the tag: it would
be published before the files could be attached.
