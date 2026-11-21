# Repository workflow configuration

## [`auto-merge.yml`](auto-merge.yml)

The workflow approves Dependabot's pull requests that only make minor or patch
updates (for a grouped pull request, every update in it), and turns on auto-merge
for them, so that they're merged once the required checks pass. Major updates are
left for review, as are updates whose type can't be determined (for an action
pinned to a commit without a version, say), and if a pull request stops being minor
or patch, its auto-merge is turned off. Dependabot waits 7 days after a release before proposing it (the
`cooldown` in [`../dependabot.yml`](../dependabot.yml)).

It merges with a merge commit, not a squash: GitHub remembers the last merge method
used with an account, and the merge is made with the maintainer's token, so a
squash would become the default for their own pull requests.

The workflow is skipped when someone other than Dependabot pushes to its pull
request, since such a run gets no Dependabot secrets.

It needs:

1. **A `WORKFLOW_TOKEN` Dependabot secret.** A run that Dependabot starts gets a
   read-only `GITHUB_TOKEN`, so the approval and the merge are made with a token of
   the maintainer's. Create a
   [fine-grained token](https://github.com/settings/personal-access-tokens) for this
   repository with these permissions:

   * Metadata: read (required)
   * Contents: read and write
   * Pull requests: read and write

   Then add it as `WORKFLOW_TOKEN` in the repository's settings under
   **Security > Secrets and variables > Dependabot** (`./settings/secrets/dependabot`).
2. **Auto-merge and merge commits allowed**, under **General > Pull Requests**:
   **Allow auto-merge** and **Allow merge commits**.
3. **Required status checks** on `main`, in a ruleset (**Rules > Rulesets**), so that
   auto-merge waits for the tests instead of merging right away: the `Tests` jobs,
   `Tests (PHARs)`, `PHPStan`, `PHPCS` and `ShellCheck`.

## [`release.yml`](release.yml)

For a pushed `v*` tag, it builds the PHARs, tests them, and attaches them to a
draft of that tag's release, along with their checksums and a build provenance
attestation. Run by hand, it only builds and tests them. It needs no secrets.

The repository has immutable releases turned on (**General > Releases**), so a
published release's tag and files can't be changed, which is why the files are
attached to a draft. To make a release:

1. Push a signed tag from `main`, once its checks have passed:

   ```sh
   git tag -s v1.2.3 -m v1.2.3
   git push origin v1.2.3
   ```

   Composer users can install the new version as soon as Packagist sees the tag.
2. When the workflow has finished, open the draft from the
   [releases](https://github.com/westonruter/phpstan-diff/releases) (the run's
   summary links to it). Its notes are generated from the pull requests merged
   since the previous tag.
3. Edit the notes, and publish it. Subscribers are notified with the notes it's
   published with, and its files and tag can't be changed after that.

Don't create the release from the web interface before pushing the tag: it would
be published before the files could be attached.
