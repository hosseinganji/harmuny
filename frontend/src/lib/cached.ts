import { api, ApiError } from './api'
import { kv } from './db'

/**
 * GET with an IndexedDB fallback: fresh data when online, the last good copy
 * when offline. `onCached` fires first with the stored copy for instant paint.
 */
export async function cachedGet<T>(path: string, query?: Record<string, any>, onCached?: (data: T) => void): Promise<T> {
  const key = 'get:' + path + (query ? '?' + new URLSearchParams(query).toString() : '')
  const stored = await kv.get<T>(key)
  if (stored !== undefined) onCached?.(stored)
  try {
    const fresh = await api.get<T>(path, query)
    await kv.set(key, fresh)
    return fresh
  } catch (e) {
    if (stored !== undefined && e instanceof ApiError && (e.status === 0 || e.status >= 500)) return stored
    throw e
  }
}
