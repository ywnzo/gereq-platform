import fs from 'node:fs';
import path from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import tailwindcss from '@tailwindcss/vite';

const hotFile = path.resolve('public/hot');
const vitePort = Number(process.env.VITE_PORT ?? 5173);

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
      const writeHot = () => {
        fs.mkdirSync(path.dirname(hotFile), { recursive: true });
        fs.writeFileSync(hotFile, String(vitePort));
      };
      const clearHot = () => {
        try {
          fs.unlinkSync(hotFile);
        } catch {
          /* ignore */
        }
      };

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

      server.watcher.add(twigGlobs);
      server.watcher.on('change', (file) => {
        if (file.endsWith('.twig') || file.endsWith('.php')) {
          server.ws.send({ type: 'full-reload', path: '*' });
        }
      });
    },
    buildStart() {
      try {
        fs.unlinkSync(hotFile);
      } catch {
        /* ignore */
      }
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
