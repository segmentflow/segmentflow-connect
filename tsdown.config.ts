import { defineConfig } from "tsdown";

export default defineConfig({
  entry: ["src/admin.ts"],
  format: "iife",
  outDir: "assets/js",
  platform: "browser",
  target: "es2020",
  minify: true,
  sourcemap: true,
  clean: true,
  globalName: "SegmentflowAdmin",
});
