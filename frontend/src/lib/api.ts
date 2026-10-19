import { apiBase } from './config'

const TOKEN_KEY = 'smp.token'
const MEDIA_KEY = 'smp.mediaKey'

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  data: any

  constructor(message: string, status: number, errors: Record<string, string[]> = {}, data: any = null) {
    super(message)
    this.status = status
    this.errors = errors
    this.data = data
  }

  /** First validation message, falling back to the general message. */
  get first(): string {
    const first = Object.values(this.errors)[0]
    return first?.[0] ?? this.message
  }
}

export const auth = {
  get token() {
    return localStorage.getItem(TOKEN_KEY)
  },
  set token(value: string | null) {
    if (value) localStorage.setItem(TOKEN_KEY, value)
    else localStorage.removeItem(TOKEN_KEY)
  },
  get mediaKey() {
    return localStorage.getItem(MEDIA_KEY)
  },
  set mediaKey(value: string | null) {
    if (value) localStorage.setItem(MEDIA_KEY, value)
    else localStorage.removeItem(MEDIA_KEY)
  },
}

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(handler: () => void) {
  onUnauthorized = handler
}

type Query = Record<string, string | number | boolean | null | undefined | (string | number)[]>

function buildUrl(path: string, query?: Query) {
  const url = new URL(apiBase() + '/api' + path, location.origin)
  for (const [k, v] of Object.entries(query ?? {})) {
    // Lists go as key[]=a&key[]=b, which Laravel reads as an array.
    if (Array.isArray(v)) v.forEach((item) => url.searchParams.append(`${k}[]`, String(item)))
    else if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v))
  }
  return url.toString()
}

export async function request<T = any>(
  method: string,
  path: string,
  options: { body?: unknown; query?: Query; signal?: AbortSignal } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (auth.token) headers.Authorization = `Bearer ${auth.token}`

  let body: BodyInit | undefined
  if (options.body instanceof FormData) {
    body = options.body
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.body)
  }

  let response: Response
  try {
    response = await fetch(buildUrl(path, options.query), { method, headers, body, signal: options.signal })
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e
    throw new ApiError('network', 0)
  }

  const data = response.status === 204 ? null : await response.json().catch(() => null)
  if (!response.ok) {
    if (response.status === 401 && auth.token) onUnauthorized?.()
    throw new ApiError(data?.message ?? response.statusText, response.status, data?.errors ?? {}, data)
  }
  return data as T
}

export const api = {
  get: <T = any>(path: string, query?: Query, signal?: AbortSignal) => request<T>('GET', path, { query, signal }),
  post: <T = any>(path: string, body?: unknown) => request<T>('POST', path, { body }),
  patch: <T = any>(path: string, body?: unknown) => request<T>('PATCH', path, { body }),
  put: <T = any>(path: string, body?: unknown) => request<T>('PUT', path, { body }),
  delete: <T = any>(path: string, query?: Query) => request<T>('DELETE', path, { query }),
}

/** URL for media elements, which cannot send headers: authenticated with the media key. */
export function mediaUrl(path: string, extra?: Query): string {
  return buildUrl(path, { k: auth.mediaKey, ...extra })
}

export function streamUrl(trackId: number) {
  return mediaUrl(`/tracks/${trackId}/stream`)
}

/** Cover URL; `version` (the track's updated_at) busts the artwork cache after edits. */
export function coverUrl(trackId: number, version?: string | null) {
  return mediaUrl(`/tracks/${trackId}/cover`, version ? { v: version.replace(/\D/g, '').slice(0, 14) } : undefined)
}

/** Absolute URLs returned by the API (avatars, playlist covers) get the media key appended. */
export function withMediaKey(url: string | null | undefined): string | null {
  if (!url) return null
  const u = new URL(url, location.origin)
  if (auth.mediaKey) u.searchParams.set('k', auth.mediaKey)
  return u.toString()
}
