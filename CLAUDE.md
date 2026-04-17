# Segmentflow Connect — agent instructions

## Release procedure (ALWAYS follow this)

This repo uses **release-please** + a manual **WordPress.org SVN deploy** workflow. Never hand-edit version strings or create tags yourself.

### Version state (release-please manages all of these)

Do not edit these by hand — release-please bumps them via the PR it opens.

- `segmentflow-connect.php` — `Version:` header + `SEGMENTFLOW_VERSION` constant
- `package.json` — `version`
- `readme.txt` — `Stable tag`
- `.release-please-manifest.json`
- `CHANGELOG.md` — generated from conventional commits

Config lives in `release-please-config.json`. Workflow: `.github/workflows/release-please.yml` (fires on push to `main`).

### Commit conventions

Use Conventional Commits. `release-please-config.json` treats sections as follows:

- `feat:` → Features (bumps minor)
- `fix:` → Bug Fixes (bumps patch)
- `perf:` → Performance (bumps patch)
- `feat!:` / `BREAKING CHANGE:` → major bump
- `refactor:` / `docs:` / `chore:` / `test:` / `ci:` → **hidden**, will NOT trigger a release PR on their own

If a change is asset-only / chore-only but needs to ship, force a release with a `Release-As:` footer (see below).

### Cutting a release

**Step 1 — land the changes on `main`.**

Either via a normal feat/fix commit, OR — if the only changes are hidden types (chore/refactor/docs/test/ci) — push an **empty** commit with a `Release-As:` footer:

```bash
git commit --allow-empty -m "chore: release 2.1.1

Release-As: 2.1.1"
git push
```

**Step 2 — let release-please open the PR.** Titled `chore: release vX.Y.Z`. Verify it bumps the four version locations above and writes a CHANGELOG entry. Ask the user before merging — this is the release gate.

**Step 3 — merge the release PR.** release-please then auto-creates git tag `vX.Y.Z` and a GitHub release. Do not create tags manually.

**Step 3a — verify the release graduated.** The post-merge release-please run can silently fail the labeling/tagging step (observed failure: `Could not resolve to a node with the global id …` when the action races GitHub's PR propagation). After merging, confirm all three:

- `git tag` shows `vX.Y.Z`
- `gh release list` shows `vX.Y.Z`
- `gh pr view <n> --json labels` on the merged PR shows label `autorelease: tagged`

If any are missing, see "Recovery" below before continuing to Step 4.

**Step 4 — deploy to WordPress.org.** Manually dispatch the `Deploy to WordPress.org SVN` workflow (`.github/workflows/deploy.yml`) with the `version` input (e.g. `2.1.1`, no `v` prefix). It checks out tag `vX.Y.Z`, builds via `pnpm build`, and uses `10up/action-wordpress-plugin-deploy` to push to SVN trunk + tag. Requires secrets `WP_ORG_SVN_USERNAME` / `WP_ORG_SVN_PASSWORD`. **Always ask the user before dispatching** — this publishes to the public WordPress.org plugin directory.

### Hard rules

- Never hand-bump version in `segmentflow-connect.php`, `package.json`, `readme.txt`, or `.release-please-manifest.json`.
- Never `git tag vX.Y.Z` manually — release-please owns tags.
- Never force-push to `main`.
- Never dispatch the WP.org deploy workflow without explicit user confirmation.
- The plugin name is `Segmentflow Connect` (preserve existing casing). Do not rebrand text copy.

### Recovery — release-please didn't graduate a merged release PR

Symptom: release-please opens a new PR proposing a version **≤ the current `.release-please-manifest.json`** (e.g. manifest is at `2.1.2` but a PR titled `chore: release v2.1.1` appears). This means a prior release PR merged without getting the `autorelease: tagged` label, so release-please lost track of the last release and is re-scanning all commits since the previous known tag.

**Do not merge that PR.** Recover instead:

1. Identify the merged release PR that never graduated (its files match the current manifest version).
2. Add the pending label: `gh pr edit <n> --add-label "autorelease: pending"`.
3. Re-run the post-merge release-please workflow run: `gh run rerun <run-id>` (the run triggered by that PR's merge commit). It will now find the pending PR, create the missing tag + GitHub release, and relabel it `autorelease: tagged`.
4. Close the bogus release PR and delete its branch: `gh pr close <bogus-n> --delete-branch`.
5. Verify per Step 3a, then proceed to Step 4.

### WP.org assets

- Plugin bundle assets ship from `assets/images/` — must be local files, no runtime CDN.
- WordPress.org directory assets (icon/banner/screenshots) live in `.wordpress-org/` and are shipped via the SVN deploy action (`ASSETS_DIR: .wordpress-org`), not in the plugin ZIP.
