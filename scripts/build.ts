/**
 * Production (and watch) frontend build via Bun's native bundler.
 * Emits Vite-compatible public/build/.vite/manifest.json for AssetManager.
 */
import { rm, mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import tailwind from 'bun-plugin-tailwind';

const root = path.resolve(import.meta.dir, '..');
const outdir = path.join(root, 'public/build');
const assetsDir = path.join(outdir, 'assets');
const entry = path.join(root, 'resources/js/admin.ts');
const entryKey = 'resources/js/admin.ts';
const watch = process.argv.includes('--watch');

async function clearHotMarker(): Promise<void> {
  try {
    await rm(path.join(root, 'public/hot'), { force: true });
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

  const manifest = {
    [entryKey]: {
      file: jsFile,
      css: cssFiles,
      isEntry: true,
      src: entryKey,
    },
  };

  const viteDir = path.join(outdir, '.vite');
  await mkdir(viteDir, { recursive: true });
  await writeFile(path.join(viteDir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
  await writeFile(path.join(outdir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
}

async function runBuild(): Promise<void> {
  await clearHotMarker();
  await rm(outdir, { recursive: true, force: true });
  await mkdir(assetsDir, { recursive: true });

  const result = await Bun.build({
    entrypoints: [entry],
    outdir: assetsDir,
    target: 'browser',
    format: 'esm',
    splitting: true,
    minify: !watch,
    sourcemap: watch ? 'linked' : 'none',
    naming: {
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

  const sizes = result.outputs.map((o) => {
    const rel = path.relative(outdir, o.path);
    return `  ${rel}  ${(o.size / 1024).toFixed(1)} kB`;
  });
  console.log(`✓ bun build → public/build (${result.outputs.length} files)`);
  for (const line of sizes) {
    console.log(line);
  }
}

await runBuild();

if (watch) {
  console.log('Watching resources/ + modules/*/Resources/assets …');
  const watcher = (async () => {
    // Prefer Bun.watch when available; fall back to rebuild on argv-only.
    const { watch: fsWatch } = await import('node:fs');
    const dirs = [
      path.join(root, 'resources'),
      path.join(root, 'modules'),
    ];
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
  })();
  await watcher;
  // Keep process alive
  await new Promise(() => {});
}
