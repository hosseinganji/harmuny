import type { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'team.paystar.smartmusic',
  appName: 'Harmony',
  webDir: 'dist',
  android: {
    // Lets the app talk to an http:// server during development; use https in production.
    allowMixedContent: true,
  },
  server: {
    androidScheme: 'https',
  },
}

export default config
