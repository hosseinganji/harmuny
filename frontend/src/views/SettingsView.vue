<script setup lang="ts">
import { Bell, ChevronRight, Download, HardDrive, Info, Languages, Palette, SlidersHorizontal } from 'lucide-vue-next'
import { computed } from 'vue'
import { LOCALES, t } from '@/lib/i18n'
import { useEqualizer } from '@/stores/equalizer'
import { useSettings } from '@/stores/settings'

const settings = useSettings()
const eq = useEqualizer()

/** Each group of related settings opens on its own page. */
const groups = computed(() => [
  {
    to: '/settings/equalizer',
    icon: SlidersHorizontal,
    title: t('eq.title'),
    hint: eq.state.enabled ? `${t('eq.on')} · ${t(`eq.preset.${eq.state.preset}`)}` : t('eq.openHint'),
    badge: eq.state.enabled ? t('eq.on') : t('eq.off'),
    active: eq.state.enabled,
  },
  {
    to: '/settings/appearance',
    icon: Palette,
    title: t('settings.appearance'),
    hint: t('settings.appearanceHint'),
    badge: t(`settings.${settings.prefs.theme ?? 'dark'}`),
  },
  {
    to: '/settings/language',
    icon: Languages,
    title: t('settings.language'),
    hint: t('settings.languageHint'),
    badge: LOCALES.find((l) => l.value === settings.prefs.language)?.label,
  },
  { to: '/settings/playback', icon: Download, title: t('settings.playback'), hint: t('settings.playbackHint') },
  { to: '/settings/notifications', icon: Bell, title: t('push.title'), hint: t('push.newSongsHint') },
  { to: '/settings/storage', icon: HardDrive, title: t('settings.storage'), hint: t('settings.storageHint') },
  { to: '/settings/about', icon: Info, title: t('settings.about'), hint: t('settings.aboutHint') },
])
</script>

<template>
  <div class="mx-auto max-w-2xl px-4 pb-6 pt-6 sm:px-6 sm:pt-10">
    <h1 class="mb-5 text-3xl font-black tracking-tight sm:text-4xl">{{ t('settings.title') }}</h1>

    <nav class="card divide-y divide-line overflow-hidden">
      <RouterLink
        v-for="g in groups"
        :key="g.to"
        :to="g.to"
        class="flex items-center gap-4 px-4 py-3.5 transition hover:bg-fg/5 active:bg-fg/10"
      >
        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent text-white"><component :is="g.icon" class="size-5" /></span>
        <span class="min-w-0 flex-1">
          <span class="block font-bold">{{ g.title }}</span>
          <span class="block truncate text-sm text-muted">{{ g.hint }}</span>
        </span>
        <span v-if="g.badge" class="shrink-0 text-xs font-semibold" :class="g.active ? 'text-accent' : 'text-subtle'">{{ g.badge }}</span>
        <ChevronRight class="size-5 shrink-0 text-subtle rtl:rotate-180" />
      </RouterLink>
    </nav>
  </div>
</template>
