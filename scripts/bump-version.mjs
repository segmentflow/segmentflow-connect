/**
 * Bump version across all version-containing files.
 *
 * Called by changesets via the "version" script in package.json:
 *   "version": "changeset version && node scripts/bump-version.mjs"
 *
 * Reads the version from package.json and syncs it to:
 * 1. segmentflow-connect.php (plugin header "Version:" field)
 * 2. segmentflow-connect.php (SEGMENTFLOW_VERSION constant)
 * 3. readme.txt ("Stable tag:" field)
 *
 * @package Segmentflow_Connect
 */

import { readFileSync, writeFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = join(__dirname, "..");

// Read version from package.json.
const packageJson = JSON.parse(readFileSync(join(rootDir, "package.json"), "utf-8"));
const version = packageJson.version;

if (!version) {
  console.error("Could not read version from package.json");
  process.exit(1);
}

console.log(`Bumping version to ${version}`);

// Update segmentflow-connect.php plugin header.
const pluginFile = join(rootDir, "segmentflow-connect.php");
let pluginContent = readFileSync(pluginFile, "utf-8");

// Update "Version: X.Y.Z" in plugin header.
pluginContent = pluginContent.replace(/^ \* Version:\s+.+$/m, ` * Version:     ${version}`);

// Update SEGMENTFLOW_VERSION constant.
pluginContent = pluginContent.replace(
  /define\(\s*'SEGMENTFLOW_VERSION',\s*'.+?'\s*\)/,
  `define( 'SEGMENTFLOW_VERSION', '${version}' )`,
);

writeFileSync(pluginFile, pluginContent);
console.log(`  Updated segmentflow-connect.php`);

// Update readme.txt "Stable tag: X.Y.Z".
const readmeFile = join(rootDir, "readme.txt");
let readmeContent = readFileSync(readmeFile, "utf-8");

readmeContent = readmeContent.replace(/^Stable tag:\s+.+$/m, `Stable tag: ${version}`);

writeFileSync(readmeFile, readmeContent);
console.log(`  Updated readme.txt`);

console.log("Version bump complete.");
