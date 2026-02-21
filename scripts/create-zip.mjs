/**
 * Create a distributable plugin .zip file.
 *
 * Includes only the files needed for the plugin to run:
 * - PHP files (plugin root + includes/)
 * - Compiled JS (assets/js/)
 * - CSS (assets/css/)
 * - Images (assets/images/)
 * - Languages (languages/)
 * - readme.txt
 * - LICENSE
 *
 * Excludes development files (src/, tests/, node_modules/, vendor/, .github/, etc.)
 *
 * @package Segmentflow_WooCommerce
 */

import { execSync } from "node:child_process";
import { existsSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = join(__dirname, "..");

const zipName = "segmentflow-woocommerce.zip";

// Files and directories to include in the zip.
const includes = [
  "segmentflow-woocommerce.php",
  "uninstall.php",
  "readme.txt",
  "LICENSE",
  "includes/",
  "assets/css/",
  "assets/js/",
  "assets/images/",
  "languages/",
];

// Filter to only existing paths.
const existingPaths = includes.filter((p) => existsSync(join(rootDir, p)));

if (existingPaths.length === 0) {
  console.error("No files found to include in the zip.");
  process.exit(1);
}

// Create zip from the root directory.
const paths = existingPaths.join(" ");
execSync(`zip -r ${zipName} ${paths}`, { cwd: rootDir, stdio: "inherit" });

console.log(`\nCreated ${zipName}`);
