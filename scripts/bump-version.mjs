/**
 * Bump the plugin version across all files that declare it.
 *
 * Usage:
 *   node scripts/bump-version.mjs <patch|minor|major|X.Y.Z>
 *
 * Examples:
 *   node scripts/bump-version.mjs patch      # 1.0.3 → 1.0.4
 *   node scripts/bump-version.mjs minor      # 1.0.3 → 1.1.0
 *   node scripts/bump-version.mjs major      # 1.0.3 → 2.0.0
 *   node scripts/bump-version.mjs 2.5.0      # sets explicit version
 *
 * Files updated:
 *   - segmentflow-connect.php  →  * Version: X.Y.Z
 *   - segmentflow-connect.php  →  define( 'SEGMENTFLOW_VERSION', 'X.Y.Z' )
 *   - readme.txt               →  Stable tag: X.Y.Z
 *   - package.json             →  "version": "X.Y.Z"
 *   - .release-please-manifest.json  →  ".": "X.Y.Z"
 *
 * @package Segmentflow_Connect
 */

import { readFileSync, writeFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = join(__dirname, "..");

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Parse a semver string into [major, minor, patch] integers.
 * Exits with an error if the string is not valid.
 *
 * @param {string} version
 * @returns {[number, number, number]}
 */
function parseVersion(version) {
  const match = version.match(/^(\d+)\.(\d+)\.(\d+)$/);
  if (!match) {
    console.error(`Invalid version: "${version}". Must be X.Y.Z (e.g. 1.2.3).`);
    process.exit(1);
  }
  return [parseInt(match[1], 10), parseInt(match[2], 10), parseInt(match[3], 10)];
}

/**
 * Increment a semver string by the given bump type.
 *
 * @param {string} current
 * @param {"patch"|"minor"|"major"} bump
 * @returns {string}
 */
function incrementVersion(current, bump) {
  const [major, minor, patch] = parseVersion(current);
  switch (bump) {
    case "patch":
      return `${major}.${minor}.${patch + 1}`;
    case "minor":
      return `${major}.${minor + 1}.0`;
    case "major":
      return `${major + 1}.0.0`;
    default:
      console.error(`Unknown bump type: "${bump}". Use patch, minor, or major.`);
      process.exit(1);
  }
}

/**
 * Read a file, apply a replacement, write it back.
 * Returns true if the file was changed, false if the pattern was not found.
 *
 * @param {string} filePath
 * @param {RegExp} pattern
 * @param {string} replacement
 * @returns {boolean}
 */
function replaceInFile(filePath, pattern, replacement) {
  const content = readFileSync(filePath, "utf-8");
  if (!pattern.test(content)) {
    return false;
  }
  writeFileSync(filePath, content.replace(pattern, replacement));
  return true;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

const arg = process.argv[2];

if (!arg) {
  console.error(
    "Usage: node scripts/bump-version.mjs <patch|minor|major|X.Y.Z>\n" +
      "  patch  — increment patch version (1.0.3 → 1.0.4)\n" +
      "  minor  — increment minor version (1.0.3 → 1.1.0)\n" +
      "  major  — increment major version (1.0.3 → 2.0.0)\n" +
      "  X.Y.Z  — set an explicit version",
  );
  process.exit(1);
}

// Read current version from the plugin file header (source of truth).
const pluginFilePath = join(rootDir, "segmentflow-connect.php");
const pluginFile = readFileSync(pluginFilePath, "utf-8");
const currentVersionMatch = pluginFile.match(/^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$/m);

if (!currentVersionMatch) {
  console.error('Could not find "Version: X.Y.Z" in segmentflow-connect.php');
  process.exit(1);
}

const currentVersion = currentVersionMatch[1];

// Determine new version.
const newVersion =
  arg === "patch" || arg === "minor" || arg === "major"
    ? incrementVersion(currentVersion, arg)
    : (parseVersion(arg), arg); // parseVersion validates the format, then use arg as-is

// Sanity check: new version must be strictly greater than current (unless same, which is a no-op warning).
const [curMaj, curMin, curPat] = parseVersion(currentVersion);
const [newMaj, newMin, newPat] = parseVersion(newVersion);
const isGreater =
  newMaj > curMaj ||
  (newMaj === curMaj && newMin > curMin) ||
  (newMaj === curMaj && newMin === curMin && newPat > curPat);
const isSame = newVersion === currentVersion;

if (isSame) {
  console.warn(
    `Warning: new version (${newVersion}) is the same as current (${currentVersion}). No changes made.`,
  );
  process.exit(0);
}

if (!isGreater) {
  console.error(
    `Error: new version (${newVersion}) is lower than current version (${currentVersion}). Use an explicit version to downgrade if intentional.`,
  );
  // Allow explicit downgrades but not via patch/minor/major bump types.
  if (arg === "patch" || arg === "minor" || arg === "major") {
    process.exit(1);
  }
}

console.log(`Bumping version: ${currentVersion} → ${newVersion}\n`);

// ---------------------------------------------------------------------------
// Update each file
// ---------------------------------------------------------------------------

const files = [
  {
    label: "segmentflow-connect.php (Version header)",
    path: join(rootDir, "segmentflow-connect.php"),
    pattern: /^(\s*\*\s*Version:\s*)(\d+\.\d+\.\d+)(\s*)$/m,
    replacement: `$1${newVersion}$3`,
  },
  {
    label: "segmentflow-connect.php (SEGMENTFLOW_VERSION constant)",
    path: join(rootDir, "segmentflow-connect.php"),
    pattern: /(define\(\s*'SEGMENTFLOW_VERSION',\s*')(\d+\.\d+\.\d+)(')/,
    replacement: `$1${newVersion}$3`,
  },
  {
    label: "readme.txt (Stable tag)",
    path: join(rootDir, "readme.txt"),
    pattern: /^(Stable tag:\s*)(\d+\.\d+\.\d+)(\s*)$/m,
    replacement: `$1${newVersion}$3`,
  },
];

let allOk = true;

for (const { label, path, pattern, replacement } of files) {
  const changed = replaceInFile(path, pattern, replacement);
  if (changed) {
    console.log(`  ✓  ${label}`);
  } else {
    console.error(`  ✗  ${label} — pattern not found, skipping`);
    allOk = false;
  }
}

// Update package.json (JSON parse/write to preserve formatting).
const packageJsonPath = join(rootDir, "package.json");
const packageJson = JSON.parse(readFileSync(packageJsonPath, "utf-8"));
packageJson.version = newVersion;
writeFileSync(packageJsonPath, JSON.stringify(packageJson, null, 2) + "\n");
console.log(`  ✓  package.json`);

// Update .release-please-manifest.json (JSON parse/write).
const manifestPath = join(rootDir, ".release-please-manifest.json");
const manifest = JSON.parse(readFileSync(manifestPath, "utf-8"));
manifest["."] = newVersion;
writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + "\n");
console.log(`  ✓  .release-please-manifest.json`);

console.log(`\nDone. Remember to:`);
console.log(`  1. Add a changelog entry in readme.txt under == Changelog ==`);
console.log(`  2. Run: git add -A && git commit -m "chore: bump version to ${newVersion}"`);
console.log(`  3. Run: pnpm plugin:zip`);

if (!allOk) {
  process.exit(1);
}
