import { defineConfig, type UserConfig } from "tsdown";

const shared: UserConfig = {
  format: "iife",
  outDir: "assets/js",
  platform: "browser",
  target: "es2020",
  minify: true,
  sourcemap: true,
  clean: true,
  // Sources are ES modules (implicitly strict). Emit the directive inside the
  // IIFE, not at file top, so JS-concatenating plugins don't leak it into
  // neighbouring scripts.
  outputOptions: { strict: false, intro: '"use strict";' },
  // Note: globalName removed — neither bundle needs a window global.
  // admin.ts is self-contained (binds to DOM on load).
  // storefront.ts is self-contained (binds event listeners on load).
};

// One config per entry: IIFE output can't be code-split, so each bundle
// must be built on its own. tsdown runs `clean` once across all configs.
export default defineConfig([
  { ...shared, entry: ["src/admin.ts"] },
  { ...shared, entry: ["src/storefront.ts"] },
]);
