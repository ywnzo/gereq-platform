import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import tailwindcss from '@tailwindcss/vite';

const hotFile = path.resolve('public/hot');
const vitePort = Number(process.env.VITE_PORT ?? 5173);

function writeHot(): void {
  fs.mkdirSync(path.dirname(hotFile), { recursive: true });
  fs.writeFileSync(hotFile, String(vitePort));
}

function clearHot(): void {
  try {
    fs.unlinkSync(hotFile);
  } catch {
    /* ignore */
  }
}

async function viteListening(port: number, timeoutMs = 120): Promise<boolean> {
  return await new Promise<boolean>((resolve) => {
    const socket = net.connect({ host: '127.0.0.1', port });
    const finish = (ok: boolean) => {
      socket.removeAllListeners();
      socket.destroy();
      resolve(ok);
    };
    socket.setTimeout(timeoutMs);
    socket.once('connect', () => finish(true));
    socket.once('error', () => finish(false));
    socket.once('timeout', () => finish(false));
  });
}

/** Writes `public/hot` (port) for PHP AssetManager + full-reload on Twig changes. */
function gereqDev(): Plugin {
  const twigGlobs = [
    'resources/views/**/*.twig',
    'modules/*/Resources/views/**/*.twig',
    'modules/*/Resources/assets/**/*.{css,ts,js}',
  ];

  return {
    name: 'gereq-dev',
    configureServer(server) {
      writeHot();
      const httpServer = server.httpServer;
      httpServer?.once('listening', writeHot);
      httpServer?.once('close', clearHot);
      process.once('exit', clearHot);
      process.once('SIGINT', () => {
        clearHot();
        process.exit();
      });
      process.once('SIGTERM', () => {
        clearHot();
        process.exit();
      });

      // `bun run build` used to delete public/hot while HMR was still up —
      // PHP then served /build and ignored Vite. Restore the marker quickly.
      const hotWatch = setInterval(() => {
        if (!fs.existsSync(hotFile)) {
          writeHot();
        }
      }, 1000);
      httpServer?.once('close', () => clearInterval(hotWatch));

      server.watcher.add(twigGlobs);
      server.watcher.on('change', (file) => {
        if (file.endsWith('.twig') || file.endsWith('.php')) {
          server.ws.send({ type: 'full-reload', path: '*' });
        }
      });
    },
    async buildStart() {
      // Don't wipe the HMR marker while `bun run dev` is still listening.
      if (await viteListening(vitePort)) {
        return;
      }
      clearHot();
    },
  };
}

export default defineConfig({
  plugins: [tailwindcss(), gereqDev()],
  publicDir: false,
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        admin: 'resources/js/admin.ts',
      },
    },
  },
  server: {
    host: '0.0.0.0',
    port: vitePort,
    strictPort: true,
    cors: true,
    // Allow nginx (host.docker.internal) and LAN hosts to fetch modules.
    allowedHosts: true,
    // HMR uses the page host (nginx admin port) — not :5173 from the browser.
    hmr: {
      path: '/@vite/',
    },
    watch: {
      usePolling: process.env.CHOKIDAR_USEPOLLING === '1',
      interval: 300,
    },
  },
});
