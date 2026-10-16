import { defineConfig, minimal2023Preset } from '@vite-pwa/assets-generator/config'

export default defineConfig({
  headLinkOptions: { preset: '2023' },
  preset: {
    ...minimal2023Preset,
    maskable: { ...minimal2023Preset.maskable, padding: 0, resizeOptions: { background: '#0b0b12' } },
    apple: { ...minimal2023Preset.apple, padding: 0, resizeOptions: { background: '#0b0b12' } },
  },
  // Full-bleed square source; logo.png is the rounded copy used inside the app.
  images: ['public/icon.png'],
})
