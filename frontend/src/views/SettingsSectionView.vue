<script setup lang="ts">
import { Check, ChevronLeft, Download, Monitor, Moon, Smartphone, Sun, Trash2 } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import DeviceSyncPanel from '@/components/DeviceSyncPanel.vue'
import { apiBase, isNative, savedApiBase, setApiBase } from '@/lib/config'
import { db, kv } from '@/lib/db'
import { LOCALES, t } from '@/lib/i18n'
import { useLibrary } from '@/stores/library'
import { useSettings } from '@/stores/settings'
import { useUi } from '@/stores/ui'

type SettingsSection = 'appearance' | 'language' | 'playback' | 'storage' | 'about'
const props = defineProps<{ section: SettingsSection }>()

const library = useLibrary()
const settings = useSettings()
const ui = useUi()
const router = useRouter()
const server = ref(savedApiBase())
const version = __APP_VERSION__
const buildDate = __BUILD_DATE__
const serverHost = computed(() => {
  try {
    return new URL(apiBase(), location.origin).host
  } catch {
    return location.host
  }
})

const installEvent = ref<any>(null)
const standalone = matchMedia('(display-mode: standalone)').matches || (navigator as any).standalone
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault()
  installEvent.value = e
})

async function install() {
  installEvent.value?.prompt()
  installEvent.value = null
}

/** Drop artwork and lyrics caches and re-download the catalog. Downloads are kept. */
async function clearCaches() {
  if ('caches' in window) {
    const keys = (await caches.keys()).filter((k) => k.includes('artwork'))
    await Promise.all(keys.map((k) => caches.delete(k)))
  }
  await db.kv.where('key').startsWith('lyrics:').delete()
  await kv.delete('library.since')
  library.sync()
  ui.toast(t('settings.cacheCleared'), 'success')
}

function saveServer() {
  setApiBase(server.value)
  ui.toast(t('toast.saved'), 'success')
}

const titles = computed<Record<SettingsSection, string>>(() => ({
  appearance: t('settings.appearance'),
  language: t('settings.language'),
  playback: t('settings.playback'),
  storage: t('settings.storage'),
  about: t('settings.about'),
}))

const themes = computed(() => [
  { value: 'dark', label: t('settings.dark'), icon: Moon },
  { value: 'light', label: t('settings.light'), icon: Sun },
  { value: 'system', label: t('settings.system'), icon: Monitor },
] as const)

function back() {
  if (history.length > 1) router.back()
  else router.push('/settings')
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4 px-4 pb-6 pt-4 sm:px-6 sm:pt-8">
    <header class="mb-1 flex items-center gap-2">
      <button class="icon-btn -ms-2 text-fg" :aria-label="t('common.back')" @click="back"><ChevronLeft class="size-7 rtl:rotate-180" /></button>
      <h1 class="flex-1 text-2xl font-black tracking-tight sm:text-3xl">{{ titles[props.section] }}</h1>
    </header>

    <!-- Appearance -->
    <template v-if="section === 'appearance'">
      <section class="card space-y-5 p-5">
        <div class="grid grid-cols-3 gap-2">
          <button
            v-for="th in themes"
            :key="th.value"
            class="flex flex-col items-center gap-1.5 rounded-2xl border-2 p-3 text-sm font-semibold transition active:scale-95"
            :class="settings.prefs.theme === th.value ? 'border-accent bg-accent/10' : 'border-line hover:bg-fg/5'"
            @click="settings.update({ theme: th.value })"
          >
            <component :is="th.icon" class="size-5" />{{ th.label }}
          </button>
        </div>
        <label class="flex cursor-pointer items-center justify-between gap-4">
          <div><div class="font-semibold">{{ t('settings.dynamicColor') }}</div><div class="text-sm text-muted">{{ t('settings.dynamicColorHint') }}</div></div>
          <input type="checkbox" class="size-5 shrink-0 accent-[var(--accent)]" :checked="settings.prefs.dynamicColor !== false" @change="settings.update({ dynamicColor: ($event.target as HTMLInputElement).checked })" />
        </label>
      </section>
    </template>

    <!-- Language -->
    <template v-else-if="section === 'language'">
      <section class="card divide-y divide-line overflow-hidden">
        <button
          v-for="l in LOCALES"
          :key="l.value"
          class="flex w-full items-center gap-3 px-5 py-4 text-start transition hover:bg-fg/5"
          :lang="l.value"
          @click="settings.update({ language: l.value })"
        >
          <span class="flex-1 text-lg font-semibold">{{ l.label }}</span>
          <Check v-if="settings.prefs.language === l.value" class="size-5 text-accent" />
        </button>
      </section>
    </template>

    <!-- Playback & downloads -->
    <template v-else-if="section === 'playback'">
      <section class="card p-5">
        <label class="flex cursor-pointer items-center justify-between gap-4">
          <div><div class="font-semibold">{{ t('settings.autoDownloadLiked') }}</div><div class="text-sm text-muted">{{ t('settings.autoDownloadHint') }}</div></div>
          <input type="checkbox" class="size-5 shrink-0 accent-[var(--accent)]" :checked="settings.prefs.autoDownloadLiked" @change="settings.update({ autoDownloadLiked: ($event.target as HTMLInputElement).checked })" />
        </label>
      </section>
      <DeviceSyncPanel />
    </template>

    <!-- Storage -->
    <template v-else-if="section === 'storage'">
      <section class="card space-y-3 p-5">
        <p class="text-sm text-muted">{{ t('settings.storageHint') }}</p>
        <button class="btn-ghost w-full" @click="clearCaches"><Trash2 class="size-4" />{{ t('settings.clearCache') }}</button>
      </section>
    </template>

    <!-- About -->
    <template v-else-if="section === 'about'">
      <section class="card flex items-center gap-4 p-5">
        <img src="/logo.png" class="size-14 rounded-[22%]" alt="" />
        <div class="min-w-0">
          <div class="font-brand text-2xl leading-tight">{{ t('app.name') }}</div>
          <div class="text-sm text-muted" dir="ltr">{{ version }} · {{ buildDate }}</div>
          <div class="truncate text-xs text-subtle" dir="ltr">{{ serverHost }}</div>
        </div>
      </section>
      <section v-if="!standalone && !isNative" class="card p-5">
        <div class="flex items-center gap-2 font-semibold"><Smartphone class="size-5 text-accent" />{{ t('settings.install') }}</div>
        <p class="mt-1 text-sm text-muted">{{ isIOS ? t('settings.installIOS') : t('settings.installHint') }}</p>
        <button v-if="installEvent" class="btn-primary mt-3 h-9" @click="install"><Download class="size-4" />{{ t('settings.installNow') }}</button>
      </section>
      <section v-if="isNative" class="card p-5">
        <label class="label">{{ t('auth.server') }}</label>
        <div class="flex gap-2"><input v-model="server" class="input" dir="ltr" /><button class="btn-ghost" @click="saveServer">{{ t('common.save') }}</button></div>
      </section>
    </template>
  </div>
</template>
