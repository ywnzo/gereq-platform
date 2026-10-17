/**
 * Build a single module's admin assets and merge into public/build/manifest.json.
 *
 * Usage: bun ./scripts/build-module.ts <moduleId>
 */
import path from 'node:path';
import {
  buildOptions,
  discoverModuleAssets,
  exists,
  outdir,
  pickCss,
  pickJs,
  readManifest,
  relOut,
  repairColorMixCss,
  root,
  writeManifestFile,
  type ManifestRecord,
} from './build-lib';
import { mkdir, rm } from 'node:fs/promises';
import { assetsDir } from './build-lib';

const watch = process.env.GEREQ_ASSET_WATCH === '1' || process.argv.includes('--watch');
const moduleId = (process.argv.slice(2).find((a) => !a.startsWith('-')) ?? '').trim().toLowerCase();

if (moduleId === '' || moduleId.includes('/') || moduleId.includes('\\')) {
  console.error('Usage: bun ./scripts/build-module.ts <moduleId>');
  process.exit(1);
}

const mods = await discoverModuleAssets(moduleId);
const mod = mods.find((m) => m.id === moduleId) ?? mods[0];
if (!mod || mod.id !== moduleId) {
  console.error(`Module "${moduleId}" not found or has no Resources/assets.`);
  process.exit(1);
}

if (mod.js === null && mod.css === null) {
  console.error(`Module "${moduleId}" has no admin.ts / admin.css assets.`);
  process.exit(1);
}

await mkdir(assetsDir, { recursive: true });
await mkdir(outdir, { recursive: true });

// Drop previous hashed outputs for this module id (best-effort).
const manifest = await readManifest();
const prev = manifest[`module:${moduleId}`];
if (prev) {
  const stale = new Set<string>();
  if (prev.file) stale.add(prev.file);
  for (const c of prev.css ?? []) stale.add(c);
  for (const rel of stale) {
    try {
      await rm(path.join(outdir, rel), { force: true });
    } catch {
      /* ignore */
    }
  }
}

const entry = mod.js ?? mod.css;
if (entry === null) {
  process.exit(1);
}

const result = await Bun.build({
  ...buildOptions(watch, {
    entry: watch ? `module-${moduleId}.[ext]` : `module-${moduleId}-[hash].min.[ext]`,
    chunk: watch
      ? `module-${moduleId}-[name].[ext]`
      : `module-${moduleId}-[name]-[hash].min.[ext]`,
    asset: watch ? `module-${moduleId}.[ext]` : `module-${moduleId}-[hash].min.[ext]`,
  }),
  entrypoints: [entry],
});

const js = pickJs(result.outputs);
const css = pickCss(result.outputs);
const cssList = css ? [css] : [];

if (mod.js !== null && js === null) {
  throw new Error(`Module JS build produced no JS for ${moduleId}`);
}
if (mod.js === null && css === null) {
  throw new Error(`Module CSS build produced no CSS for ${moduleId}`);
}

const file = js ?? css ?? '';
const src = path.relative(root, entry).split(path.sep).join('/');

const record: ManifestRecord = {
  file,
  css: cssList,
  moduleId,
  src,
};

manifest[`module:${moduleId}`] = record;
await writeManifestFile(manifest);

if (cssList.length > 0) {
  await repairColorMixCss(cssList);
}

const sizeLines = result.outputs.map(
  (o) => `  ${relOut(o.path)}  ${(o.size / 1024).toFixed(1)} kB`,
);
console.log(`✓ module:${moduleId} → public/build`);
for (const line of sizeLines) {
  console.log(line);
}

// Touch for watch consumers.
if (await exists(path.join(outdir, '.dev'))) {
  const { writeDevMarker } = await import('./build-lib');
  await writeDevMarker();
}
