import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, auth } from '@/lib/api'
import { db } from '@/lib/db'
import type { Preferences, User } from '@/lib/types'
import { useSettings } from './settings'

interface SessionPayload {
  token?: string
  user: User
  preferences: Preferences
  media_key: string
}

const USER_KEY = 'smp.user'

export const useAuth = defineStore('auth', () => {
  const user = ref<User | null>(JSON.parse(localStorage.getItem(USER_KEY) ?? 'null'))
  const isLoggedIn = computed(() => !!user.value && !!auth.token)
  const isAdmin = computed(() => user.value?.role === 'admin')

  function applySession(payload: SessionPayload) {
    if (payload.token) auth.token = payload.token
    auth.mediaKey = payload.media_key
    user.value = payload.user
    localStorage.setItem(USER_KEY, JSON.stringify(payload.user))
    useSettings().hydrate(payload.preferences)
  }

  async function login(username: string, password: string) {
    applySession(await api.post<SessionPayload>('/auth/login', { username, password, device: deviceName() }))
  }

  /** Returns 'active' when the account can be used right away (the first admin), else 'pending'. */
  async function register(data: { name: string; username: string; email?: string; password: string }) {
    const res = await api.post<SessionPayload & { status?: string }>('/auth/register', { ...data, device: deviceName() })
    if (res.token) {
      applySession(res)
      return 'active'
    }
    return 'pending'
  }

  /** Refresh the profile; keeps working offline with the cached user. */
  async function refresh() {
    if (!auth.token) return
    try {
      applySession(await api.get<SessionPayload>('/me'))
    } catch {
      // Offline or server down: keep the cached session.
    }
  }

  async function logout(remote = true) {
    if (remote) await api.post('/auth/logout').catch(() => {})
    auth.token = null
    auth.mediaKey = null
    user.value = null
    localStorage.removeItem(USER_KEY)
    // Personal caches go; downloads and device music stay on this device.
    await db.kv.clear()
  }

  return { user, isLoggedIn, isAdmin, applySession, login, register, refresh, logout }
})

function deviceName(): string {
  const ua = navigator.userAgent
  const os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'macOS' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : 'Device'
  const browser = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : ''
  return `${os}${browser ? ' · ' + browser : ''}`
}
