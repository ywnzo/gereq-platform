/**
 * Shared helpers for kernel + per-module Bun builds.
 */
import { unlinkSync } from 'node:fs';
import { access, mkdir, readFile, readdir, rm, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import tailwind from 'bun-plugin-tailwind';

export const root = path.resolve(import.meta.dir, '..');
export const outdir = path.join(root, 'public/build');
export const assetsDir = path.join(outdir, 'assets');
export const entryKey = 'resources/js/admin.ts';
export const kernelEntry = path.join(root, 'resources/js/admin.ts');
export const manifestPath = path.join(outdir, 'manifest.json');
/** Server-side watch flag (also used by isWatch()). */
export const devMarker = path.join(outdir, '.dev');
/** Browser poll target (non-dot; safer than .dev behind some proxies). */
export const watchStatePath = path.join(outdir, 'watch.json');

export type ManifestRecord = {
  file: string;
  css: string[];
  isEntry?: boolean;
  src?: string;
  moduleId?: string;
};

export type ModuleAssetPaths = {
  id: string;
  path: string;
  js: string | null;
  css: string | null;
};

/** Serialize builds that share the manifest and output directory. */
export async function withBuildLock<T>(build: () => Promise<T>): Promise<T> {
  const lock = path.join(outdir, '.build-lock');
  await mkdir(outdir, { recursive: true });
  const deadline = Date.now() + 30000;
  while (true) {
    try {
      await mkdir(lock);
      break;
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error;
      try {
        if (Date.now() - (await stat(lock)).mtimeMs > 120000) {
          await rm(lock, { recursive: true, force: true });
          continue;
        }
      } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'ENOENT') continue;
        throw error;
      }
      if (Date.now() >= deadline) throw new Error('Timed out waiting for the frontend build lock');
      await Bun.sleep(100);
    }
  }
  try {
    return await build();
  } finally {
    await rm(lock, { recursive: true, force: true });
  }
}

export async function exists(p: string): Promise<boolean> {
  try {
    await access(p);
    return true;
  } catch {
    return false;
  }
}

export function relOut(filePath: string): string {
  return path.relative(outdir, filePath).split(path.sep).join('/');
}

export async function readManifest(): Promise<Record<string, ManifestRecord>> {
  if (!(await exists(manifestPath))) {
    return {};
  }
  try {
    const raw = JSON.parse(await readFile(manifestPath, 'utf8')) as Record<string, ManifestRecord>;
    return raw && typeof raw === 'object' ? raw : {};
  } catch {
    return {};
  }
}

export async function writeManifestFile(manifest: Record<string, ManifestRecord>): Promise<void> {
  await mkdir(outdir, { recursive: true });
  await writeFile(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
}

export async function clearLegacyViteMarkers(): Promise<void> {
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

/**
 * Watch marker for admin live-reload (writes `.dev` + `watch.json`).
 * - `files`: CSS/JS fingerprints (CSS can rehydrate; JS forces reload)
 * - `viewsAt`: bumped when a .twig file changes (server HTML → full reload)
 */
export async function writeDevMarker(opts?: { viewsAt?: number }): Promise<void> {
  let viewsAt = 0;
  for (const file of [watchStatePath, devMarker]) {
    try {
      const prev = JSON.parse(await readFile(file, 'utf8')) as { viewsAt?: unknown };
      if (typeof prev.viewsAt === 'number' && Number.isFinite(prev.viewsAt)) {
        viewsAt = prev.viewsAt;
        break;
      }
    } catch {
      /* try next */
    }
  }
  if (opts?.viewsAt !== undefined) {
    viewsAt = opts.viewsAt;
  }

  const files: Record<string, string> = {};
  try {
    const names = await readdir(assetsDir);
    for (const name of names) {
      if (!/\.(css|js)$/i.test(name)) {
        continue;
      }
      const st = await stat(path.join(assetsDir, name));
      files[name] = `${st.size}:${Math.round(st.mtimeMs)}`;
    }
  } catch {
    /* assets dir may be empty during early boot */
  }
  const body = JSON.stringify({ at: Date.now(), mode: 'watch', viewsAt, files }, null, 2) + '\n';
  await mkdir(outdir, { recursive: true });
  await writeFile(devMarker, body);
  await writeFile(watchStatePath, body);
}

export async function clearDevMarker(): Promise<void> {
  for (const file of [devMarker, watchStatePath]) {
    try {
      await rm(file, { force: true });
    } catch {
      /* ignore */
    }
  }
}

/** Sync cleanup for process signal handlers (async may not finish before exit). */
export function clearDevMarkerSync(): void {
  for (const file of [devMarker, watchStatePath]) {
    try {
      unlinkSync(file);
    } catch {
      /* ignore */
    }
  }
}

/** Remove kernel artefacts only — keep module-* files for lazy module builds. */
export async function cleanKernelAssets(): Promise<void> {
  await mkdir(assetsDir, { recursive: true });
  let names: string[] = [];
  try {
    names = await readdir(assetsDir);
  } catch {
    return;
  }
  await Promise.all(
    names.map(async (name) => {
      if (name.startsWith('module-')) {
        return;
      }
      try {
        await rm(path.join(assetsDir, name), { force: true, recursive: true });
      } catch {
        /* ignore */
      }
    }),
  );
}

export async function repairColorMixCss(cssRelPaths: string[]): Promise<void> {
  const patchPath = path.join(root, 'resources/css/kernel/color-mix-fix.css');
  const patch = await readFile(patchPath, 'utf8');
  const supportsRe =
    /@supports\s*\(\s*color:\s*color-mix\(\s*in\s+lab\s*,\s*red\s*,\s*red\s*\)\s*\)\s*\{/g;

  for (const rel of cssRelPaths) {
    const abs = path.join(outdir, rel);
    if (!(await exists(abs))) {
      continue;
    }
    let css = await readFile(abs, 'utf8');
    css = unwrapAtSupportsBlocks(css, supportsRe);
    if (!css.includes('/* color-mix-fix */')) {
      css += `\n/* color-mix-fix */\n${patch}\n`;
    }
    await writeFile(abs, css);
  }
}

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

export function pickCss(outputs: Bun.BuildArtifact[]): string | null {
  const css = outputs.filter((o) => o.path.endsWith('.css') && !o.path.endsWith('.css.map'));
  if (css.length === 0) {
    return null;
  }
  const entryCss = css.find((o) => o.kind === 'entry-point');
  return relOut((entryCss ?? css[0]).path);
}

export function pickJs(outputs: Bun.BuildArtifact[]): string | null {
  const js = outputs.filter((o) => o.path.endsWith('.js') && !o.path.endsWith('.js.map'));
  if (js.length === 0) {
    return null;
  }
  const entryJs = js.find((o) => o.kind === 'entry-point');
  return relOut((entryJs ?? js[0]).path);
}

export function buildOptions(
  watch: boolean,
  extraNaming?: Record<string, string>,
): Omit<Parameters<typeof Bun.build>[0], 'entrypoints'> {
  // Prod: *.min.js / *.min.css (minified). Watch keeps plain names (unminified).
  const naming = watch
    ? {
        entry: '[name].[ext]',
        chunk: '[name].[ext]',
        asset: '[name].[ext]',
        ...extraNaming,
      }
    : {
        entry: '[name]-[hash].min.[ext]',
        chunk: '[name]-[hash].min.[ext]',
        asset: '[name]-[hash].min.[ext]',
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

export async function discoverModuleAssets(filterId?: string): Promise<ModuleAssetPaths[]> {
  const args = ['php', path.join(root, 'scripts/module-assets.php')];
  if (filterId) {
    args.push(filterId);
  }
  const proc = Bun.spawn(args, {
    cwd: root,
    stdout: 'pipe',
    stderr: 'inherit',
  });
  const body = await new Response(proc.stdout).text();
  if ((await proc.exited) !== 0) {
    throw new Error('Module asset discovery failed');
  }
  const packages: { id: string; path: string; js?: string | null; css?: string | null }[] =
    JSON.parse(body);
  const out: ModuleAssetPaths[] = [];
  for (const mod of packages) {
    const jsPath = path.join(mod.path, 'Resources/assets/js/admin.ts');
    const cssPath = path.join(mod.path, 'Resources/assets/css/admin.css');
    const js = (await exists(jsPath)) ? jsPath : null;
    const css = (await exists(cssPath)) ? cssPath : null;
    if (js === null && css === null) {
      continue;
    }
    out.push({ id: mod.id, path: mod.path, js, css });
  }
  return out;
}

export function moduleIdsInManifest(manifest: Record<string, ManifestRecord>): string[] {
  return Object.keys(manifest)
    .filter((k) => k.startsWith('module:'))
    .map((k) => k.slice('module:'.length));
}
