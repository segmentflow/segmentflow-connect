import { defineConfig } from "tsdown";

export default defineConfig({
  entry: ["src/admin.ts", "src/storefront.ts"],
  format: "iife",
  outDir: "assets/js",
  platform: "browser",
  target: "es2020",
  minify: true,
  sourcemap: true,
  clean: true,
  // Note: globalName removed — neither bundle needs a window global.
  // admin.ts is self-contained (binds to DOM on load).
  // storefront.ts is self-contained (binds event listeners on load).
});
