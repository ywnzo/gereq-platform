/**
 * Kernel frontend build via Bun's native bundler.
 * Writes/merges public/build/manifest.json for Gereq\View\RenderEngine.
 *
 * - resources/js/admin.ts → kernel JS + kernel CSS only
 * - Module assets: `bun run build:module -- <id>` (lazy on enable/update/migrate)
 *
 * `bun run build`  → hashed kernel files
 * `bun run dev`    → stable names + .dev marker; rebuilds kernel + modules already in manifest
 */
import { rm, mkdir } from 'node:fs/promises';
import path from 'node:path';
import {
  assetsDir,
  buildOptions,
  clearDevMarker,
  clearDevMarkerSync,
  clearLegacyViteMarkers,
  discoverModuleAssets,
  entryKey,
  kernelEntry,
  moduleIdsInManifest,
  outdir,
  pickCss,
  pickJs,
  readManifest,
  relOut,
  repairColorMixCss,
  root,
  writeDevMarker,
  writeManifestFile,
  withBuildLock,
  type ManifestRecord,
} from './build-lib';

const watch = process.argv.includes('--watch');

async function buildKernel(preserveModules: boolean): Promise<{
  manifest: Record<string, ManifestRecord>;
  sizeLines: string[];
}> {
  return withBuildLock(async () => {
    await clearLegacyViteMarkers();
    await mkdir(assetsDir, { recursive: true });

    const previous = preserveModules ? await readManifest() : {};
    const preserved: Record<string, ManifestRecord> = {};
    if (preserveModules) {
      for (const [key, rec] of Object.entries(previous)) {
        if (key.startsWith('module:')) {
          preserved[key] = rec;
        }
      }
    }

    if (!preserveModules) {
      await rm(outdir, { recursive: true, force: true });
      await mkdir(assetsDir, { recursive: true });
      await clearDevMarker();
    } else {
      if (!watch) {
        await clearDevMarker();
      }
    }

    const sizeLines: string[] = [];
    const adminResult = await Bun.build({
      ...buildOptions(watch),
      entrypoints: [kernelEntry],
    });

    const adminJs = pickJs(adminResult.outputs);
    const adminCss = pickCss(adminResult.outputs);
    if (adminJs === null) {
      throw new Error('Bun build produced no admin JS entry');
    }

    const manifest: Record<string, ManifestRecord> = {
      ...preserved,
      [entryKey]: {
        file: adminJs,
        css: adminCss ? [adminCss] : [],
        isEntry: true,
        src: entryKey,
      },
    };

    for (const o of adminResult.outputs) {
      sizeLines.push(`  ${relOut(o.path)}  ${(o.size / 1024).toFixed(1)} kB`);
    }

    await writeManifestFile(manifest);

    const cssToRepair = new Set<string>();
    for (const c of manifest[entryKey].css) {
      cssToRepair.add(c);
    }
    await repairColorMixCss([...cssToRepair]);

    if (watch) {
      await writeDevMarker();
    }

    return { manifest, sizeLines };
  });
}

async function rebuildModule(id: string): Promise<void> {
  const proc = Bun.spawn(['bun', path.join(root, 'scripts/build-module.ts'), id], {
    cwd: root,
    stdout: 'inherit',
    stderr: 'inherit',
  });
  if ((await proc.exited) !== 0) {
    throw new Error(`Module rebuild failed for ${id}`);
  }
}

async function runBuild(): Promise<void> {
  // Always preserve lazy module:* entries; only kernel artefacts are replaced.
  // Full clean: `rm -rf public/build && bun run build`.
  const { manifest, sizeLines } = await buildKernel(true);
  const mode = watch ? 'watch' : 'prod';
  const moduleCount = moduleIdsInManifest(manifest).length;
  console.log(
    `✓ bun build [${mode}] → public/build (${sizeLines.length} kernel files, ${moduleCount} module entries preserved)`,
  );
  for (const line of sizeLines) {
    console.log(line);
  }
  if (watch) {
    console.log('  (module assets rebuild only when already in manifest; enable builds new ones)');
  }
}

await runBuild();

if (watch) {
  console.log('Watching resources/ + modules (assets + .twig) …');
  console.log('Open/refresh admin once — poll uses /build/watch.json (CSS rehydrate, JS/Twig reload).');
  const { watch: fsWatch } = await import('node:fs');
  const watchReadyAt = Date.now() + 1500;
  let timer: ReturnType<typeof setTimeout> | null = null;
  let pendingKernel = false;
  let pendingViews = false;
  const pendingModules = new Set<string>();

  const flush = async () => {
    timer = null;
    try {
      if (pendingKernel) {
        pendingKernel = false;
        await buildKernel(true);
      }
      const ids = [...pendingModules];
      pendingModules.clear();
      for (const id of ids) {
        await rebuildModule(id);
      }
      if (pendingViews) {
        pendingViews = false;
        // Twig is server-rendered — bump viewsAt so the browser reloads HTML.
        await writeDevMarker({ viewsAt: Date.now() });
        console.log('✓ twig change → watch.json viewsAt bumped (browser reload)');
      }
    } catch (err) {
      console.error('Rebuild failed:', err);
    }
  };

  const schedule = () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => void flush(), 120);
  };

  const onFile = async (filename: string) => {
    // fs.watch often emits a burst when the watcher is attached — ignore startup noise.
    if (Date.now() < watchReadyAt) {
      return;
    }
    const norm = filename.split(path.sep).join('/');
    if (/\.twig$/i.test(norm)) {
      pendingViews = true;
      schedule();
      return;
    }
    if (!/\.(ts|js|css|tsx|jsx)$/i.test(norm)) {
      return;
    }
    if (norm.startsWith('resources/')) {
      pendingKernel = true;
      schedule();
      return;
    }
    // modules/<Dir>/... or modules/<Dir>/Submodules/<Sub>/...
    const m = norm.match(/^modules\/([^/]+)\//);
    if (!m) {
      return;
    }
    const manifest = await readManifest();
    const known = new Set(moduleIdsInManifest(manifest));
    // Resolve disk id via discovery (folder name ≠ module id).
    const all = await discoverModuleAssets();
    for (const mod of all) {
      if (!known.has(mod.id)) {
        continue;
      }
      const rel = path.relative(root, mod.path).split(path.sep).join('/');
      if (norm.startsWith(rel + '/') || norm.startsWith(rel + '/Resources/')) {
        pendingModules.add(mod.id);
        schedule();
        return;
      }
    }
  };

  for (const dir of [path.join(root, 'resources'), path.join(root, 'modules')]) {
    try {
      fsWatch(dir, { recursive: true }, (_event, filename) => {
        if (!filename) return;
        const base = path.relative(root, dir);
        void onFile(path.join(base, String(filename)).split(path.sep).join('/'));
      });
    } catch (err) {
      console.warn(`Cannot watch ${dir}:`, err);
    }
  }

  let shuttingDown = false;
  const shutdown = (signal: string) => {
    if (shuttingDown) {
      return;
    }
    shuttingDown = true;
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    clearDevMarkerSync();
    console.log(`Stopped watch (${signal}) — removed public/build/.dev`);
    process.exit(0);
  };
  process.once('SIGINT', () => shutdown('SIGINT'));
  process.once('SIGTERM', () => shutdown('SIGTERM'));

  await new Promise(() => {});
}
