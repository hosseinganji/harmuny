import { usePreferredDark } from '@vueuse/core'
import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { applyLocale } from '@/lib/i18n'
import type { Preferences } from '@/lib/types'

const KEY = 'smp.prefs'

/** User preferences, applied locally at once and synced to the profile in the background. */
export const useSettings = defineStore('settings', () => {
  const prefs = ref<Preferences>({
    theme: 'dark',
    dynamicColor: true,
    autoDownloadLiked: false,
    ...JSON.parse(localStorage.getItem(KEY) ?? '{}'),
  })
  const prefersDark = usePreferredDark()

  const theme = computed(() =>
    prefs.value.theme === 'system' ? (prefersDark.value ? 'dark' : 'light') : (prefs.value.theme ?? 'dark'),
  )

  function apply() {
    const root = document.documentElement
    root.dataset.theme = theme.value
    applyLocale()
    document.querySelector('meta[name=theme-color]')?.setAttribute('content', theme.value === 'dark' ? '#08080d' : '#f6f6fa')
  }

  watch([prefs, theme], () => {
    localStorage.setItem(KEY, JSON.stringify(prefs.value))
    apply()
  }, { deep: true, immediate: true })

  let syncTimer: ReturnType<typeof setTimeout> | undefined
  function update(patch: Partial<Preferences>, sync = true) {
    prefs.value = { ...prefs.value, ...patch }
    if (!sync) return
    clearTimeout(syncTimer)
    syncTimer = setTimeout(() => {
      api.patch('/me', { preferences: prefs.value }).catch(() => {})
    }, 600)
  }

  /** Server preferences win on login, so settings follow the user across devices. */
  function hydrate(server: Preferences | undefined) {
    if (server && Object.keys(server).length) update(server, false)
  }

  return { prefs, theme, update, hydrate }
})
