<script setup lang="ts">
import { Home, Library, Search } from 'lucide-vue-next'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { t } from '@/lib/i18n'

const route = useRoute()
const items = computed(() => [
  { to: '/', icon: Home, label: t('nav.home'), match: (p: string) => p === '/' },
  { to: '/search', icon: Search, label: t('nav.search'), match: (p: string) => p.startsWith('/search') },
  { to: '/library', icon: Library, label: t('nav.library'), match: (p: string) => p.startsWith('/library') || p.startsWith('/playlist') || p.startsWith('/artist') },
])
</script>

<template>
  <nav class="liquid mx-auto flex max-w-md gap-1 rounded-full p-1 lg:hidden">
    <RouterLink
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="flex flex-1 flex-col items-center gap-0.5 rounded-full pb-1.5 pt-2 text-[10px] font-semibold transition duration-300 active:scale-95"
      :class="item.match(route.path) ? 'liquid-active text-accent' : 'text-muted'"
    >
      <component :is="item.icon" class="size-6" :stroke-width="item.match(route.path) ? 2.3 : 1.8" />
      {{ item.label }}
    </RouterLink>
  </nav>
</template>
