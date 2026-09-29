/**
 * Production (and watch) frontend build via Bun's native bundler.
 * Writes public/build/manifest.json for Gereq\View\RenderEngine.
 *
 * `bun run build`  → hashed files (immutable cache)
 * `bun run dev`    → stable assets/admin.js|css + public/build/.dev marker
 *                  → writes into the host tree mounted by Docker (:/var/www/html)
 */
import { rm, mkdir, writeFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import tailwind from 'bun-plugin-tailwind';

const root = path.resolve(import.meta.dir, '..');
const outdir = path.join(root, 'public/build');
const assetsDir = path.join(outdir, 'assets');
const entry = path.join(root, 'resources/js/admin.ts');
const entryKey = 'resources/js/admin.ts';
const watch = process.argv.includes('--watch');
const devMarker = path.join(outdir, '.dev');

async function clearLegacyViteMarkers(): Promise<void> {
  try {
    await rm(path.join(root, 'public/hot'), { force: true });
  } catch {
    /* ignore */
  }
  try {
    await rm(path.join(outdir, '.vite'), { recursive: true, force: true });
  } catch {
    /* ignore */
  }
}

async function writeManifest(outputs: Bun.BuildArtifact[]): Promise<void> {
  let jsFile: string | null = null;
  const cssFiles: string[] = [];

  for (const output of outputs) {
    const rel = path.relative(outdir, output.path).split(path.sep).join('/');
    if (output.kind === 'entry-point' && rel.endsWith('.js')) {
      jsFile = rel;
    }
    if (rel.endsWith('.css')) {
      cssFiles.push(rel);
    }
  }

  // Bun may attach CSS as a sibling of the JS entry (kind asset/chunk).
  if (jsFile === null) {
    const js = outputs.find((o) => o.path.endsWith('.js'));
    if (js) {
      jsFile = path.relative(outdir, js.path).split(path.sep).join('/');
    }
  }

  if (jsFile === null) {
    throw new Error('Bun build produced no JS entry artifact');
  }

  // Prefer a single CSS next to the entry (drop duplicate hashed leftovers in watch).
  const cssUnique = [...new Set(cssFiles)];

  const manifest = {
    [entryKey]: {
      file: jsFile,
      css: cssUnique,
      isEntry: true,
      src: entryKey,
    },
  };

  const body = JSON.stringify(manifest, null, 2) + '\n';
  await writeFile(path.join(outdir, 'manifest.json'), body);
}

async function writeDevMarker(): Promise<void> {
  await writeFile(
    devMarker,
    JSON.stringify({ at: Date.now(), mode: 'watch' }, null, 2) + '\n',
  );
}

async function clearDevMarker(): Promise<void> {
  try {
    await rm(devMarker, { force: true });
  } catch {
    /* ignore */
  }
}

/** Remove previous watch/prod artefacts without deleting outdir (avoids brief 404s). */
async function cleanAssetsDir(): Promise<void> {
  await mkdir(assetsDir, { recursive: true });
  let names: string[] = [];
  try {
    names = await readdir(assetsDir);
  } catch {
    return;
  }
  await Promise.all(
    names.map(async (name) => {
      try {
        await rm(path.join(assetsDir, name), { force: true, recursive: true });
      } catch {
        /* ignore */
      }
    }),
  );
}

async function runBuild(): Promise<void> {
  await clearLegacyViteMarkers();

  if (watch) {
    await mkdir(outdir, { recursive: true });
    await cleanAssetsDir();
  } else {
    await rm(outdir, { recursive: true, force: true });
    await mkdir(assetsDir, { recursive: true });
    await clearDevMarker();
  }

  const result = await Bun.build({
    entrypoints: [entry],
    outdir: assetsDir,
    target: 'browser',
    format: 'esm',
    splitting: false,
    minify: !watch,
    sourcemap: watch ? 'linked' : 'none',
    naming: watch
      ? {
          entry: '[name].[ext]',
          chunk: '[name].[ext]',
          asset: '[name].[ext]',
        }
      : {
          entry: '[name]-[hash].[ext]',
          chunk: '[name]-[hash].[ext]',
          asset: '[name]-[hash].[ext]',
        },
    plugins: [tailwind],
    define: {
      'process.env.NODE_ENV': JSON.stringify(watch ? 'development' : 'production'),
    },
    throw: true,
  });

  await writeManifest(result.outputs);
  if (watch) {
    await writeDevMarker();
  }

  const sizes = result.outputs.map((o) => {
    const rel = path.relative(outdir, o.path);
    return `  ${rel}  ${(o.size / 1024).toFixed(1)} kB`;
  });
  const mode = watch ? 'watch' : 'prod';
  console.log(`✓ bun build [${mode}] → public/build (${result.outputs.length} files)`);
  for (const line of sizes) {
    console.log(line);
  }
  if (watch) {
    console.log('  (Docker: volume mount picks this up; refresh or auto-reload)');
  }
}

await runBuild();

if (watch) {
  console.log('Watching resources/ + modules/*/Resources/assets …');
  const { watch: fsWatch } = await import('node:fs');
  const dirs = [path.join(root, 'resources'), path.join(root, 'modules')];
  let timer: ReturnType<typeof setTimeout> | null = null;
  const schedule = () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(async () => {
      try {
        await runBuild();
      } catch (err) {
        console.error('Rebuild failed:', err);
      }
    }, 120);
  };
  for (const dir of dirs) {
    try {
      fsWatch(dir, { recursive: true }, (_event, filename) => {
        if (!filename) return;
        if (!/\.(ts|js|css|tsx|jsx)$/i.test(String(filename))) return;
        schedule();
      });
    } catch (err) {
      console.warn(`Cannot watch ${dir}:`, err);
    }
  }
  await new Promise(() => {});
}
