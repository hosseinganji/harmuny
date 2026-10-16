<script setup lang="ts">
import { Download, Monitor, Moon, Smartphone, Sun, Trash2 } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import DeviceSyncPanel from '@/components/DeviceSyncPanel.vue'
import EqualizerPanel from '@/components/EqualizerPanel.vue'
import { isNative, savedApiBase, setApiBase } from '@/lib/config'
import { db, kv } from '@/lib/db'
import { t } from '@/lib/i18n'
import { useLibrary } from '@/stores/library'
import { useSettings } from '@/stores/settings'
import { useUi } from '@/stores/ui'

const library = useLibrary()
const settings = useSettings()
const ui = useUi()
const server = ref(savedApiBase())

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

const themes = computed(() => [
  { value: 'dark', label: t('settings.dark'), icon: Moon },
  { value: 'light', label: t('settings.light'), icon: Sun },
  { value: 'system', label: t('settings.system'), icon: Monitor },
] as const)
</script>

<template>
  <div class="px-4 pb-4 pt-6 sm:px-6 sm:pt-10">
    <h1 class="text-3xl font-black tracking-tight sm:text-4xl">{{ t('settings.title') }}</h1>
  </div>

  <!-- min-w-0 on the cards: the scrolling preset row must not widen the grid column past the screen. -->
  <div class="mx-auto grid max-w-5xl grid-cols-1 gap-4 px-4 sm:px-6 lg:grid-cols-2 [&>*]:min-w-0">
    <EqualizerPanel class="lg:row-span-2" />

    <!-- Appearance -->
    <section class="card space-y-5 p-5">
      <h2 class="text-lg font-bold">{{ t('settings.appearance') }}</h2>
      <div class="grid grid-cols-3 gap-2">
        <button
          v-for="th in themes"
          :key="th.value"
          class="flex flex-col items-center gap-1.5 rounded-xl border-2 p-3 text-sm font-semibold transition"
          :class="settings.prefs.theme === th.value ? 'border-accent bg-accent/10' : 'border-line hover:bg-fg/5'"
          @click="settings.update({ theme: th.value })"
        >
          <component :is="th.icon" class="size-5" />{{ th.label }}
        </button>
      </div>
      <label class="flex cursor-pointer items-center justify-between gap-4">
        <div><div class="font-semibold">{{ t('settings.dynamicColor') }}</div><div class="text-sm text-muted">{{ t('settings.dynamicColorHint') }}</div></div>
        <input type="checkbox" class="size-5 accent-[var(--accent)]" :checked="settings.prefs.dynamicColor !== false" @change="settings.update({ dynamicColor: ($event.target as HTMLInputElement).checked })" />
      </label>
    </section>

    <!-- Playback & app -->
    <section class="card space-y-4 p-5">
      <h2 class="text-lg font-bold">{{ t('settings.app') }}</h2>
      <label class="flex cursor-pointer items-center justify-between gap-4">
        <div><div class="font-semibold">{{ t('settings.autoDownloadLiked') }}</div><div class="text-sm text-muted">{{ t('settings.autoDownloadHint') }}</div></div>
        <input type="checkbox" class="size-5 accent-[var(--accent)]" :checked="settings.prefs.autoDownloadLiked" @change="settings.update({ autoDownloadLiked: ($event.target as HTMLInputElement).checked })" />
      </label>
      <div v-if="!standalone && !isNative" class="rounded-xl bg-accent/10 p-4">
        <div class="flex items-center gap-2 font-semibold"><Smartphone class="size-5 text-accent" />{{ t('settings.install') }}</div>
        <p class="mt-1 text-sm text-muted">{{ isIOS ? t('settings.installIOS') : t('settings.installHint') }}</p>
        <button v-if="installEvent" class="btn-primary mt-3 h-9" @click="install"><Download class="size-4" />{{ t('settings.installNow') }}</button>
      </div>
      <div v-if="isNative">
        <label class="label">{{ t('auth.server') }}</label>
        <div class="flex gap-2"><input v-model="server" class="input" dir="ltr" /><button class="btn-ghost" @click="saveServer">{{ t('common.save') }}</button></div>
      </div>
      <button class="btn-ghost w-full" @click="clearCaches"><Trash2 class="size-4" />{{ t('settings.clearCache') }}</button>
    </section>

    <DeviceSyncPanel class="lg:col-span-2" />
  </div>
</template>
