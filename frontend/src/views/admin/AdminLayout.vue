<script setup lang="ts">
import { BarChart3, CloudUpload, LayoutDashboard, Radar, Send, Users } from 'lucide-vue-next'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { t } from '@/lib/i18n'

const route = useRoute()
const tabs = computed(() => [
  { name: 'admin', label: t('admin.dashboard'), icon: LayoutDashboard },
  { name: 'admin-reports', label: t('reports.title'), icon: BarChart3 },
  { name: 'admin-users', label: t('admin.users'), icon: Users },
  { name: 'admin-upload', label: t('upload.tab'), icon: CloudUpload },
  { name: 'admin-discovery', label: t('discovery.tab'), icon: Radar },
  { name: 'admin-telegram', label: t('admin.telegram'), icon: Send },
])
</script>

<template>
  <div class="glass sticky top-0 z-20 px-4 pb-3 pt-5 sm:px-6">
    <h1 class="mb-3 text-2xl font-black tracking-tight sm:text-3xl">{{ t('nav.admin') }}</h1>
    <nav class="flex gap-2 overflow-x-auto scrollbar-none">
      <RouterLink v-for="tab in tabs" :key="tab.name" :to="{ name: tab.name }" class="chip" :class="{ 'chip-active': route.name === tab.name }">
        <component :is="tab.icon" class="size-4" />{{ tab.label }}
      </RouterLink>
    </nav>
  </div>
  <div class="mx-auto max-w-5xl px-4 py-4 sm:px-6">
    <RouterView v-slot="{ Component }">
      <Transition name="fade" mode="out-in"><div :key="$route.name as string"><component :is="Component" /></div></Transition>
    </RouterView>
  </div>
</template>
