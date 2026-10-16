import { Capacitor } from '@capacitor/core'

const SERVER_KEY = 'smp.server'

export const isNative = Capacitor.isNativePlatform()

/**
 * Base URL of the backend. The web build is served next to the API, so it is
 * empty there. The native app has no same-origin server and needs one set.
 */
export function apiBase(): string {
  const saved = localStorage.getItem(SERVER_KEY)
  if (saved) return saved.replace(/\/+$/, '')
  return (import.meta.env.VITE_API_URL ?? '').replace(/\/+$/, '')
}

export function setApiBase(url: string) {
  if (url.trim()) localStorage.setItem(SERVER_KEY, url.trim())
  else localStorage.removeItem(SERVER_KEY)
}

export function savedApiBase(): string {
  return localStorage.getItem(SERVER_KEY) ?? ''
}
