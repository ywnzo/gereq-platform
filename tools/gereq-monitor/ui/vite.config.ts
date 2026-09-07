import { defineConfig } from 'vite';

export default defineConfig({
  base: '/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    target: 'es2022',
  },
  server: {
    port: 8773,
    proxy: {
      '/api': 'http://127.0.0.1:8772',
    },
  },
});
