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
const activeIndex = computed(() => items.value.findIndex((item) => item.match(route.path)))
// translateX runs left-to-right even in RTL, so flip it there.
const dir = computed(() => (document.documentElement.dir === 'rtl' ? -1 : 1))
</script>

<template>
  <nav class="liquid relative mx-auto flex max-w-md rounded-full p-1 lg:hidden">
    <!-- One bubble glides to the selected tab instead of each tab lighting up on its own. -->
    <span
      v-if="activeIndex >= 0"
      class="liquid-active pointer-events-none absolute inset-y-1 start-1 rounded-full transition-transform duration-500 ease-[var(--ease-sheet)]"
      :style="{ width: `calc((100% - 0.5rem) / ${items.length})`, transform: `translateX(${activeIndex * 100 * dir}%)` }"
    />
    <RouterLink
      v-for="(item, i) in items"
      :key="item.to"
      :to="item.to"
      class="relative flex flex-1 flex-col items-center gap-0.5 rounded-full pb-1.5 pt-2 text-[10px] font-semibold transition-colors duration-300 active:scale-95"
      :class="i === activeIndex ? 'text-accent' : 'text-muted'"
    >
      <component :is="item.icon" class="size-6 transition-transform duration-300" :class="{ 'scale-110': i === activeIndex }" :stroke-width="i === activeIndex ? 2.3 : 1.8" />
      {{ item.label }}
    </RouterLink>
  </nav>
</template>
