<script setup lang="ts">
import { Activity, Check, Database, HardDrive, ListMusic, Music, Send, UserCheck, Users, X } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Avatar from '@/components/Avatar.vue'
import { api } from '@/lib/api'
import { bytes, count, longDuration, relativeTime } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { User } from '@/lib/types'
import { useUi } from '@/stores/ui'

interface Stats {
  users: Record<string, number>
  tracks: number
  library_bytes: number
  library_seconds: number
  channels: number
  playlists: number
  plays_today: number
  plays_week: number
  active_today: number
  cache: { bytes: number; files: number; limit_bytes: number }
  cached_tracks: number
  pending_downloads: number
}

const ui = useUi()
const stats = ref<Stats | null>(null)
const pending = ref<User[]>([])
const telegram = ref<{ status: string } | null>(null)

const cards = computed(() => {
  const s = stats.value
  if (!s) return []
  return [
    { icon: Music, label: t('admin.tracks'), value: count(s.tracks), sub: longDuration(s.library_seconds) },
    { icon: Users, label: t('admin.users'), value: count(s.users.active ?? 0), sub: t('admin.pendingCount', { n: s.users.pending ?? 0 }) },
    { icon: Activity, label: t('admin.playsToday'), value: count(s.plays_today), sub: t('admin.playsWeek', { n: s.plays_week }) },
    { icon: UserCheck, label: t('admin.activeToday'), value: count(s.active_today), sub: '' },
    { icon: ListMusic, label: t('library.playlists'), value: count(s.playlists), sub: '' },
    { icon: Database, label: t('admin.libraryBytes'), value: bytes(s.library_bytes), sub: t('admin.channelsCount', { n: s.channels }) },
  ]
})

async function load() {
  const [s, p, tg] = await Promise.all([
    api.get<Stats>('/admin/stats'),
    api.get<{ data: User[] }>('/admin/users', { status: 'pending' }),
    api.get<{ status: string }>('/admin/telegram').catch(() => null),
  ])
  stats.value = s
  pending.value = p.data
  telegram.value = tg
}

async function decide(user: User, approve: boolean) {
  if (approve) await api.patch(`/admin/users/${user.id}`, { status: 'active' })
  else if (confirm(t('admin.rejectConfirm', { name: user.name }))) await api.delete(`/admin/users/${user.id}`)
  else return
  ui.toast(approve ? t('admin.approved', { name: user.name }) : t('admin.rejected'), 'success')
  load()
}

// Refresh while downloads are running so the progress moves.
let timer: ReturnType<typeof setInterval>
onMounted(() => {
  load()
  timer = setInterval(() => stats.value?.pending_downloads && load(), 10000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <RouterLink
    v-if="telegram && telegram.status !== 'logged_in'"
    to="/admin/telegram"
    class="mb-4 flex items-center gap-3 rounded-2xl bg-amber-500/10 p-4 text-amber-400 transition hover:bg-amber-500/15"
  >
    <Send class="size-6" />
    <div><div class="font-bold">{{ t('admin.connectTelegram') }}</div><div class="text-sm opacity-80">{{ t('admin.connectTelegramHint') }}</div></div>
  </RouterLink>

  <section v-if="pending.length" class="card mb-4 p-4">
    <h2 class="mb-3 font-bold">{{ t('admin.pendingTitle') }} <span class="text-accent">{{ count(pending.length) }}</span></h2>
    <div class="space-y-2">
      <div v-for="u in pending" :key="u.id" class="flex items-center gap-3 rounded-xl bg-fg/5 p-3">
        <Avatar :name="u.name" :src="u.avatar_url" />
        <div class="min-w-0 flex-1">
          <div class="truncate font-semibold">{{ u.name }}</div>
          <div class="truncate text-xs text-subtle" dir="ltr">@{{ u.username }} · {{ relativeTime(u.created_at) }}</div>
        </div>
        <button class="btn-primary h-9 px-3" @click="decide(u, true)"><Check class="size-4" /><span class="hidden sm:inline">{{ t('admin.approve') }}</span></button>
        <button class="btn-danger h-9 px-3" @click="decide(u, false)"><X class="size-4" /></button>
      </div>
    </div>
  </section>

  <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
    <template v-if="stats">
      <div v-for="c in cards" :key="c.label" class="card p-4">
        <component :is="c.icon" class="mb-3 size-6 text-accent" />
        <div class="text-2xl font-black">{{ c.value }}</div>
        <div class="text-sm font-medium text-muted">{{ c.label }}</div>
        <div v-if="c.sub" class="mt-1 text-xs text-subtle">{{ c.sub }}</div>
      </div>
    </template>
    <div v-for="i in 6" v-else :key="i" class="skeleton h-32 rounded-2xl" />
  </div>

  <section v-if="stats" class="card mt-4 p-4">
    <div class="flex items-center gap-3">
      <HardDrive class="size-6 text-accent" />
      <div class="flex-1">
        <div class="font-bold">{{ t('admin.serverCache') }}</div>
        <div class="text-sm text-muted">
          {{ t('admin.storedOnServer', { n: count(stats.cached_tracks), total: count(stats.tracks) }) }}
          <template v-if="stats.pending_downloads"> · {{ t('admin.downloading', { n: count(stats.pending_downloads) }) }}</template>
        </div>
      </div>
      <div class="text-sm font-semibold tabular">{{ bytes(stats.cache.bytes) }}<template v-if="stats.cache.limit_bytes"> / {{ bytes(stats.cache.limit_bytes) }}</template></div>
    </div>
    <div class="mt-3 h-2 overflow-hidden rounded-full bg-fg/10">
      <div class="h-full rounded-full bg-accent transition-all" :style="{ width: `${stats.tracks ? Math.min(100, (stats.cached_tracks / stats.tracks) * 100) : 0}%` }" />
    </div>
  </section>
</template>
