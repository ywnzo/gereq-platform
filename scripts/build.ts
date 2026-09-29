/**
 * Production (and watch) frontend build via Bun's native bundler.
 * Writes public/build/manifest.json for Gereq\View\RenderEngine.
 *
 * - resources/js/admin.ts → kernel JS + kernel CSS
 * - modules/<Name>/Resources/assets/css/admin.css → per-module CSS (only when enabled)
 *
 * `bun run build`  → hashed files (immutable cache)
 * `bun run dev`    → stable names + public/build/.dev marker
 */
import { rm, mkdir, writeFile, readdir, readFile, access } from 'node:fs/promises';
import path from 'node:path';
import tailwind from 'bun-plugin-tailwind';

const root = path.resolve(import.meta.dir, '..');
const outdir = path.join(root, 'public/build');
const assetsDir = path.join(outdir, 'assets');
const entry = path.join(root, 'resources/js/admin.ts');
const entryKey = 'resources/js/admin.ts';
const watch = process.argv.includes('--watch');
const devMarker = path.join(outdir, '.dev');

type ManifestRecord = {
  file: string;
  css: string[];
  isEntry?: boolean;
  src?: string;
  moduleId?: string;
};

type ModuleStyle = {
  id: string;
  cssPath: string;
};

async function exists(p: string): Promise<boolean> {
  try {
    await access(p);
    return true;
  } catch {
    return false;
  }
}

async function discoverModuleStyles(): Promise<ModuleStyle[]> {
  const modulesDir = path.join(root, 'modules');
  let names: string[] = [];
  try {
    names = await readdir(modulesDir);
  } catch {
    return [];
  }

  const out: ModuleStyle[] = [];
  for (const name of names.sort()) {
    const dir = path.join(modulesDir, name);
    const cssPath = path.join(dir, 'Resources/assets/css/admin.css');
    const modulePhp = path.join(dir, 'module.php');
    if (!(await exists(cssPath)) || !(await exists(modulePhp))) {
      continue;
    }
    const php = await readFile(modulePhp, 'utf8');
    const match = php.match(/['"]id['"]\s*=>\s*['"]([^'"]+)['"]/);
    if (!match) {
      console.warn(`Skip module CSS (no id in module.php): ${name}`);
      continue;
    }
    out.push({ id: match[1], cssPath });
  }
  return out;
}

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

function relOut(filePath: string): string {
  return path.relative(outdir, filePath).split(path.sep).join('/');
}

/**
 * LightningCSS (via Tailwind optimize) breaks `color-mix(..., var(--x) N%, var(--y))`:
 * it emits a solid `var(--x)` fallback and only restores some properties inside
 * `@supports (color: color-mix(in lab, red, red))`. Result: badges / action
 * buttons / bulk-bar look fully solid-colored.
 *
 * Unwrap the incomplete @supports blocks, then append a raw soft-tint patch
 * that never goes through LightningCSS again.
 */
async function repairColorMixCss(cssRelPaths: string[]): Promise<void> {
  const patchPath = path.join(root, 'resources/css/kernel/color-mix-fix.css');
  const patch = await readFile(patchPath, 'utf8');
  const supportsRe =
    /@supports\s*\(\s*color:\s*color-mix\(\s*in\s+lab\s*,\s*red\s*,\s*red\s*\)\s*\)\s*\{/g;

  for (const rel of cssRelPaths) {
    const abs = path.join(outdir, rel);
    let css = await readFile(abs, 'utf8');
    css = unwrapAtSupportsBlocks(css, supportsRe);
    if (!css.includes('/* color-mix-fix */')) {
      css += `\n/* color-mix-fix */\n${patch}\n`;
    }
    await writeFile(abs, css);
  }
}

/** Expand `@supports (…){ … }` matched by `startRe` — keep inner rules, drop the wrapper. */
function unwrapAtSupportsBlocks(css: string, startRe: RegExp): string {
  let out = '';
  let last = 0;
  startRe.lastIndex = 0;
  let match: RegExpExecArray | null;
  while ((match = startRe.exec(css)) !== null) {
    out += css.slice(last, match.index);
    const bodyStart = match.index + match[0].length;
    let depth = 1;
    let i = bodyStart;
    for (; i < css.length; i++) {
      const ch = css[i];
      if (ch === '{') depth++;
      else if (ch === '}') {
        depth--;
        if (depth === 0) break;
      }
    }
    out += css.slice(bodyStart, i);
    last = i + 1;
    startRe.lastIndex = last;
  }
  out += css.slice(last);
  return out;
}

function pickCss(outputs: Bun.BuildArtifact[]): string | null {
  const css = outputs.filter((o) => o.path.endsWith('.css') && !o.path.endsWith('.css.map'));
  if (css.length === 0) {
    return null;
  }
  // Prefer entry-point CSS when present.
  const entryCss = css.find((o) => o.kind === 'entry-point');
  return relOut((entryCss ?? css[0]).path);
}

function pickJs(outputs: Bun.BuildArtifact[]): string | null {
  const js = outputs.filter((o) => o.path.endsWith('.js') && !o.path.endsWith('.js.map'));
  if (js.length === 0) {
    return null;
  }
  const entryJs = js.find((o) => o.kind === 'entry-point');
  return relOut((entryJs ?? js[0]).path);
}

async function writeManifestFile(manifest: Record<string, ManifestRecord>): Promise<void> {
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

function buildOptions(extraNaming?: Record<string, string>): Parameters<typeof Bun.build>[0] {
  const naming = watch
    ? {
        entry: '[name].[ext]',
        chunk: '[name].[ext]',
        asset: '[name].[ext]',
        ...extraNaming,
      }
    : {
        entry: '[name]-[hash].[ext]',
        chunk: '[name]-[hash].[ext]',
        asset: '[name]-[hash].[ext]',
        ...extraNaming,
      };

  return {
    outdir: assetsDir,
    target: 'browser',
    format: 'esm',
    splitting: false,
    minify: !watch,
    sourcemap: watch ? 'linked' : 'none',
    naming,
    plugins: [tailwind],
    define: {
      'process.env.NODE_ENV': JSON.stringify(watch ? 'development' : 'production'),
    },
    throw: true,
  };
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

  const moduleStyles = await discoverModuleStyles();
  const manifest: Record<string, ManifestRecord> = {};
  const sizeLines: string[] = [];

  const adminResult = await Bun.build({
    ...buildOptions(),
    entrypoints: [entry],
  });

  const adminJs = pickJs(adminResult.outputs);
  const adminCss = pickCss(adminResult.outputs);
  if (adminJs === null) {
    throw new Error('Bun build produced no admin JS entry');
  }
  manifest[entryKey] = {
    file: adminJs,
    css: adminCss ? [adminCss] : [],
    isEntry: true,
    src: entryKey,
  };
  for (const o of adminResult.outputs) {
    sizeLines.push(`  ${relOut(o.path)}  ${(o.size / 1024).toFixed(1)} kB`);
  }

  for (const mod of moduleStyles) {
    const result = await Bun.build({
      ...buildOptions({
        entry: watch ? `module-${mod.id}.[ext]` : `module-${mod.id}-[hash].[ext]`,
        asset: watch ? `module-${mod.id}.[ext]` : `module-${mod.id}-[hash].[ext]`,
      }),
      entrypoints: [mod.cssPath],
    });
    const css = pickCss(result.outputs);
    if (css === null) {
      throw new Error(`Module CSS build produced no CSS for ${mod.id}`);
    }
    manifest[`module:${mod.id}`] = {
      file: css,
      css: [css],
      moduleId: mod.id,
      src: path.relative(root, mod.cssPath).split(path.sep).join('/'),
    };
    for (const o of result.outputs) {
      sizeLines.push(`  ${relOut(o.path)}  ${(o.size / 1024).toFixed(1)} kB`);
    }
  }

  await writeManifestFile(manifest);

  const cssToRepair = new Set<string>();
  for (const rec of Object.values(manifest)) {
    for (const c of rec.css) cssToRepair.add(c);
    if (rec.file.endsWith('.css')) cssToRepair.add(rec.file);
  }
  await repairColorMixCss([...cssToRepair]);

  if (watch) {
    await writeDevMarker();
  }

  const mode = watch ? 'watch' : 'prod';
  console.log(`✓ bun build [${mode}] → public/build (${sizeLines.length} files, ${moduleStyles.length} module CSS)`);
  for (const line of sizeLines) {
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
