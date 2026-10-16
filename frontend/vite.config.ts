import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [
      vue(),
      tailwindcss(),
      VitePWA({
        registerType: 'autoUpdate',
        includeAssets: ['logo.png', 'favicon.ico', 'apple-touch-icon-180x180.png'],
        manifest: {
          name: 'Harmony',
          short_name: 'Harmony',
          description: 'Your music, synced from Telegram, online and offline.',
          lang: 'en',
          dir: 'ltr',
          theme_color: '#0b0b12',
          background_color: '#0b0b12',
          display: 'standalone',
          orientation: 'any',
          start_url: '/',
          scope: '/',
          categories: ['music', 'entertainment'],
          icons: [
            { src: 'pwa-64x64.png', sizes: '64x64', type: 'image/png' },
            { src: 'pwa-192x192.png', sizes: '192x192', type: 'image/png' },
            { src: 'pwa-512x512.png', sizes: '512x512', type: 'image/png' },
            { src: 'maskable-icon-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
          ],
          shortcuts: [
            { name: 'Search', url: '/search' },
            { name: 'Downloads', url: '/library/downloads' },
          ],
        },
        workbox: {
          globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2}'],
          // icon.png is only the source the home-screen icons are generated from.
          globIgnores: ['icon.png'],
          navigateFallback: '/index.html',
          navigateFallbackDenylist: [/^\/api\//],
          maximumFileSizeToCacheInBytes: 5 * 1024 * 1024,
          runtimeCaching: [
            {
              // Artwork is immutable per URL, so serve it from cache first (works offline).
              urlPattern: ({ url }) => /\/api\/(tracks\/\d+\/cover|avatars\/\d+|playlists\/\d+\/cover)/.test(url.pathname),
              handler: 'CacheFirst',
              options: {
                cacheName: 'artwork',
                expiration: { maxEntries: 3000, maxAgeSeconds: 60 * 60 * 24 * 60 },
                cacheableResponse: { statuses: [0, 200] },
              },
            },
          ],
        },
        devOptions: { enabled: false },
      }),
    ],
    // Shown at the bottom of the profile drawer.
    define: {
      __APP_VERSION__: JSON.stringify(process.env.npm_package_version ?? '1.0.0'),
      __BUILD_DATE__: JSON.stringify(new Date().toISOString().slice(0, 10)),
    },
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
      host: true,
      proxy: {
        '/api': { target: env.VITE_DEV_API ?? 'http://127.0.0.1:8000', changeOrigin: true },
      },
    },
    build: {
      target: 'es2022',
      chunkSizeWarningLimit: 900,
    },
  }
})
