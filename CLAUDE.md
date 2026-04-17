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

**Step 4 — deploy to WordPress.org.** Manually dispatch the `Deploy to WordPress.org SVN` workflow (`.github/workflows/deploy.yml`) with the `version` input (e.g. `2.1.1`, no `v` prefix). It checks out tag `vX.Y.Z`, builds via `pnpm build`, and uses `10up/action-wordpress-plugin-deploy` to push to SVN trunk + tag. Requires secrets `WP_ORG_SVN_USERNAME` / `WP_ORG_SVN_PASSWORD`. **Always ask the user before dispatching** — this publishes to the public WordPress.org plugin directory.

### Hard rules

- Never hand-bump version in `segmentflow-connect.php`, `package.json`, `readme.txt`, or `.release-please-manifest.json`.
- Never `git tag vX.Y.Z` manually — release-please owns tags.
- Never force-push to `main`.
- Never dispatch the WP.org deploy workflow without explicit user confirmation.
- The plugin name is `Segmentflow Connect` (preserve existing casing). Do not rebrand text copy.

### WP.org assets

- Plugin bundle assets ship from `assets/images/` — must be local files, no runtime CDN.
- WordPress.org directory assets (icon/banner/screenshots) live in `.wordpress-org/` and are shipped via the SVN deploy action (`ASSETS_DIR: .wordpress-org`), not in the plugin ZIP.
