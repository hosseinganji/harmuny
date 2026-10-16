<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { t } from '@/lib/i18n'

const route = useRoute()
const tabs = computed(() => [
  { name: 'library', label: t('library.playlists') },
  { name: 'songs', label: t('library.songs') },
  { name: 'artists', label: t('library.artists') },
  { name: 'liked', label: t('library.liked') },
  { name: 'downloads', label: t('library.downloads') },
  { name: 'device', label: t('library.device') },
  { name: 'history', label: t('library.history') },
])
</script>

<template>
  <div class="glass sticky top-0 z-20 px-4 pb-3 pt-5 sm:px-6">
    <h1 class="mb-3 text-2xl font-black tracking-tight sm:text-3xl">{{ t('nav.library') }}</h1>
    <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 scrollbar-none sm:-mx-6 sm:px-6">
      <RouterLink v-for="tab in tabs" :key="tab.name" :to="{ name: tab.name }" class="chip" :class="{ 'chip-active': route.name === tab.name }">
        {{ tab.label }}
      </RouterLink>
    </nav>
  </div>
  <RouterView v-slot="{ Component }">
    <Transition name="fade" mode="out-in"><div :key="$route.name as string"><component :is="Component" /></div></Transition>
  </RouterView>
</template>
