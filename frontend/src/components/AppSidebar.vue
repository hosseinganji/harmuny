<script setup lang="ts">
import { BarChart3, Download, HardDrive, Heart, History, Home, Library, ListMusic, Plus, Search, Settings, Shield, UsersRound } from 'lucide-vue-next'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { t } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { usePlaylists } from '@/stores/playlists'
import Avatar from './Avatar.vue'
import CoverMosaic from './CoverMosaic.vue'

const emit = defineEmits<{ newPlaylist: [] }>()
const auth = useAuth()
const playlists = usePlaylists()
const route = useRoute()

const nav = computed(() => [
  { to: '/', icon: Home, label: t('nav.home'), exact: true },
  { to: '/search', icon: Search, label: t('nav.search') },
  { to: '/library', icon: Library, label: t('nav.library') },
  { to: '/jam', icon: UsersRound, label: t('jam.title') },
])
const collections = computed(() => [
  { to: '/library/liked', icon: Heart, label: t('library.liked') },
  { to: '/library/downloads', icon: Download, label: t('library.downloads') },
  { to: '/library/device', icon: HardDrive, label: t('library.device') },
  { to: '/library/history', icon: History, label: t('library.history') },
  { to: '/settings', icon: Settings, label: t('settings.title') },
])

function active(to: string, exact = false) {
  return exact ? route.path === to : route.path === to || (to !== '/library' && route.path.startsWith(to + '/')) || (to === '/library' && route.path === '/library')
}
</script>

<template>
  <aside class="hidden w-64 shrink-0 flex-col gap-2 p-2 lg:flex xl:w-72">
    <nav class="card space-y-1 p-3">
      <RouterLink to="/" class="mb-2 flex items-center gap-2.5 px-2 py-1">
        <img src="/logo.png" class="size-8 rounded-[22%]" alt="" />
        <span class="font-brand text-2xl leading-none">{{ t('app.name') }}</span>
      </RouterLink>
      <RouterLink
        v-for="item in nav"
        :key="item.to"
        :to="item.to"
        class="flex items-center gap-4 rounded-xl px-3 py-2.5 font-semibold transition"
        :class="active(item.to, item.exact) ? 'bg-fg/10 text-fg' : 'text-muted hover:text-fg'"
      >
        <component :is="item.icon" class="size-5.5" />{{ item.label }}
      </RouterLink>
      <RouterLink
        v-if="auth.isAdmin"
        to="/admin/reports"
        class="flex items-center gap-4 rounded-xl px-3 py-2.5 font-semibold transition"
        :class="route.path.startsWith('/admin/reports') ? 'bg-fg/10 text-fg' : 'text-muted hover:text-fg'"
      >
        <BarChart3 class="size-5.5" />{{ t('reports.title') }}
      </RouterLink>
      <RouterLink
        v-if="auth.isAdmin"
        to="/admin"
        class="flex items-center gap-4 rounded-xl px-3 py-2.5 font-semibold transition"
        :class="route.path.startsWith('/admin') && !route.path.startsWith('/admin/reports') ? 'bg-fg/10 text-fg' : 'text-muted hover:text-fg'"
      >
        <Shield class="size-5.5" />{{ t('nav.admin') }}
      </RouterLink>
    </nav>

    <div class="card flex min-h-0 flex-1 flex-col p-3">
      <div class="space-y-0.5">
        <RouterLink
          v-for="item in collections"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition"
          :class="route.path === item.to ? 'bg-fg/10 text-fg' : 'text-muted hover:bg-fg/5 hover:text-fg'"
        >
          <component :is="item.icon" class="size-4.5" />{{ item.label }}
        </RouterLink>
      </div>

      <div class="mb-1 mt-4 flex items-center px-3">
        <span class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-subtle"><ListMusic class="size-4" />{{ t('library.playlists') }}</span>
        <button class="icon-btn ms-auto size-8" :aria-label="t('playlist.new')" @click="emit('newPlaylist')"><Plus class="size-4.5" /></button>
      </div>
      <div class="-mx-1 min-h-0 flex-1 space-y-0.5 overflow-y-auto px-1">
        <RouterLink
          v-for="p in playlists.mine"
          :key="p.id"
          :to="{ name: 'playlist', params: { id: p.id } }"
          class="flex items-center gap-3 rounded-xl p-1.5 transition"
          :class="route.params.id == String(p.id) && route.name === 'playlist' ? 'bg-fg/10' : 'hover:bg-fg/5'"
        >
          <div class="w-10 shrink-0"><CoverMosaic :cover-url="p.cover_url" :track-ids="p.preview_track_ids" rounded="rounded-md" /></div>
          <div class="min-w-0">
            <div class="truncate text-sm font-semibold">{{ p.name }}</div>
            <div class="flex items-center gap-1 truncate text-xs text-subtle">
              <UsersRound v-if="!p.is_owner" class="size-3" />
              {{ p.is_owner ? t('playlist.songs', { n: p.tracks_count ?? 0 }) : p.owner.name }}
            </div>
          </div>
        </RouterLink>
        <p v-if="!playlists.mine.length" class="px-3 py-4 text-sm text-subtle">{{ t('playlist.noneYet') }}</p>
      </div>
    </div>

    <RouterLink to="/profile" class="card flex items-center gap-3 p-3 transition hover:bg-surface-2">
      <Avatar :name="auth.user?.name" :src="auth.user?.avatar_url" />
      <div class="min-w-0">
        <div class="truncate text-sm font-semibold">{{ auth.user?.name }}</div>
        <div class="truncate text-xs text-subtle" dir="ltr">@{{ auth.user?.username }}</div>
      </div>
    </RouterLink>
  </aside>
</template>
