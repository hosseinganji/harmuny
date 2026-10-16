import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import './style.css'

/**
 * iOS 26 home-screen apps report a viewport one status-bar height shorter than the space they really
 * get (WebKit bug 301108), which leaves the bottom bar floating above an empty strip. With the opaque
 * status bar the shortfall is that same status-bar height again, so the real height is the reported
 * one plus half the difference to the screen. Only applied when the shortfall is clearly there.
 */
function fixIOSViewport() {
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent)
  const standalone = (navigator as { standalone?: boolean }).standalone === true
  if (!ios || !standalone) return
  const landscape = innerWidth > innerHeight
  const screenHeight = landscape ? Math.min(screen.width, screen.height) : Math.max(screen.width, screen.height)
  const missing = screenHeight - innerHeight
  const root = document.documentElement
  if (missing > 70 && missing < 160) root.style.setProperty('--app-height', `${innerHeight + missing / 2}px`)
  else root.style.removeProperty('--app-height')
}
fixIOSViewport()
addEventListener('resize', fixIOSViewport)
addEventListener('orientationchange', () => setTimeout(fixIOSViewport, 300))

createApp(App).use(createPinia()).use(router).mount('#app')
