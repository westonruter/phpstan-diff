# Repository workflow configuration

## [`auto-merge.yml`](auto-merge.yml)

The workflow approves each of Dependabot's pull requests, and turns on auto-merge
for those that only make minor or patch updates (for a grouped pull request, every
update in it), so that they're squash-merged once the required checks pass. Major
updates are left for review. Dependabot waits 7 days after a release before
proposing it (the `cooldown` in [`../dependabot.yml`](../dependabot.yml)).

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
2. **Auto-merge allowed**, under **General > Pull Requests > Allow auto-merge**.
3. **Required status checks** on `main`, in a ruleset (**Rules > Rulesets**), so that
   auto-merge waits for the tests instead of merging right away: the `Tests` jobs,
   `Tests (PHARs)`, `PHPStan`, `PHPCS` and `ShellCheck`.

## [`release.yml`](release.yml)

For a pushed `v*` tag, it builds the PHARs, tests them, and attaches them to that
tag's release, with their checksums and a build provenance attestation. Run by hand,
it only builds and tests them. It needs no secrets.
