import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import laravel from 'laravel-vite-plugin'
import path from 'node:path'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  resolve: { alias: { '@': path.resolve(__dirname, 'resources/js') } },
  plugins: [
    laravel({ input: ['resources/css/app.css', 'resources/js/app.tsx'], refresh: true }),
    react(),
    tailwindcss(),
    // Service worker only: the manifest is a static file (public/manifest.webmanifest) and registration is in app.tsx.
    // The SW is emitted to public/build/sw.js and served from "/" by routes/web.php so it can control the whole origin.
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'resources/js',
      filename: 'sw.ts',
      manifest: false,
      injectRegister: false,
      injectManifest: {
        globPatterns: ['assets/**/*.{js,css,woff2}'],
        // Only the Latin subsets are precached; other scripts load on demand via unicode-range.
        globIgnores: ['**/*cyrillic*', '**/*vietnamese*', '**/*symbols*', '**/*latin-ext*'],
        // Entries are relative to public/build, but sw.js is served from "/", so point them at /build/.
        modifyURLPrefix: { 'assets/': '/build/assets/' },
        // Static offline page served from public/ (bump the revision when it changes).
        additionalManifestEntries: [{ url: '/offline.html', revision: 'v1' }],
      },
    }),
  ],
  server: { watch: { ignored: ['**/storage/framework/views/**', '**/web-prototype/**'] } },
})
