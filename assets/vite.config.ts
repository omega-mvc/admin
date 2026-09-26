import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// includes/Core/Enqueue.php reads the build manifest from
// assets/dist/.vite/manifest.json and picks the chunk flagged `isEntry`, so
// renaming src/main.ts does not require a PHP change.
export default defineConfig(({ mode }) => ({
  plugins: [vue(), tailwindcss()],
  // Load-bearing, and easy to miss. Vue's ESM bundler build branches on
  // `process.env.NODE_ENV`, and Vite normally inlines that for a client build —
  // but NOT in library mode, where it is assumed the consumer's bundler defines
  // it. Here the consumer is the browser, which has no `process` at all, so
  // every one of those references survived into dist/ and threw
  // `ReferenceError: process is not defined` while the module was still being
  // evaluated. Symptom: a completely blank page with no error anywhere,
  // because it happens before Vue mounts and before any fetch.
  define: {
    'process.env.NODE_ENV': JSON.stringify(mode === 'production' ? 'production' : 'development'),
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    origin: 'http://localhost:5173',
    cors: true,
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
    sourcemap: true,
    target: 'es2022',
    // Library mode: WordPress enqueues the bundle directly, so there is no
    // index.html and the output must stay an ES module.
    lib: {
      entry: fileURLToPath(new URL('./src/main.ts', import.meta.url)),
      formats: ['es'],
      fileName: () => 'admin-suite.js',
    },
    rollupOptions: {
      output: {
        // The hash in both patterns is load-bearing, not tidiness. Without it
        // the emitted names are byte-stable across builds, so the browser keys
        // its cache on a URL that never changes and keeps serving the previous
        // bundle until someone clears the cache by hand.
        //
        // It also silently defeats the cache buster in Enqueue. Library mode
        // makes the enqueued entry a facade — a shim whose whole body is
        // `import "./assets/main.js"` — so hashing *that* file produces a
        // constant, because the shim's bytes never change. With a hash here,
        // a rebuild renames the real chunk, the shim's import specifier changes
        // with it, and Enqueue's md5 of the shim finally moves too.
        assetFileNames: 'assets/[name]-[hash][extname]',
        chunkFileNames: 'assets/[name]-[hash].js',
        sourcemapFileNames: 'assets/[name]-[hash].js.map',
      },
    },
  },
}))
