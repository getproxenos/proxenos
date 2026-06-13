/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],

  // The SPA is decoupled and mounted under /app/ so it sits beside Symfony's
  // public/index.php front controller without colliding with the `/` route.
  // `vite build` emits hashed assets under ../public/app, which FrankenPHP/Caddy
  // serves as static files in prod (see docker/php/Dockerfile + compose).
  base: '/app/',
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
  },

  // Dev: Vite serves the SPA with HMR on its own port and proxies API calls to
  // the running FrankenPHP `app` container (compose publishes :8080). There is
  // no /api route yet in Phase 0.0 — this is the forward-looking dev wiring.
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
    },
  },

  test: {
    environment: 'node',
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
  },
})
