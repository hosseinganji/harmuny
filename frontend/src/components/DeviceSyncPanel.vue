<script setup lang="ts">
import { CloudDownload, Loader2, Pause, Play, Trash2 } from 'lucide-vue-next'
import { computed, onMounted } from 'vue'
import { bytes, count } from '@/lib/format'
import { t } from '@/lib/i18n'
import { type SyncMode, useDeviceSync } from '@/stores/deviceSync'
import { useDownloads } from '@/stores/downloads'
import { usePlaylists } from '@/stores/playlists'
import { useUi } from '@/stores/ui'

const sync = useDeviceSync()
const downloads = useDownloads()
const playlists = usePlaylists()
const ui = useUi()

const modes: SyncMode[] = ['off', 'all', 'liked', 'selected']
const total = computed(() => sync.target.length)
const percent = computed(() => (total.value ? Math.round((sync.onDevice / total.value) * 100) : 0))
const active = computed(() => sync.config.mode !== 'off' && downloads.queued > 0 && !sync.paused)

onMounted(() => {
  if (!playlists.loaded) playlists.load()
})

async function removeOutside() {
  if (!confirm(t('deviceSync.removeOutsideConfirm'))) return
  const n = await sync.removeOutside()
  ui.toast(t('deviceSync.removed', { n }), 'success')
}
</script>

<template>
  <section class="card space-y-4 p-5">
    <div class="flex items-start gap-3">
      <CloudDownload class="mt-0.5 size-6 shrink-0 text-accent" />
      <div>
        <h2 class="text-lg font-bold">{{ t('deviceSync.title') }}</h2>
        <p class="text-sm text-muted">{{ t('deviceSync.hint') }}</p>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
      <button
        v-for="m in modes"
        :key="m"
        class="rounded-xl border-2 p-3 text-sm font-semibold transition"
        :class="sync.config.mode === m ? 'border-accent bg-accent/10' : 'border-line hover:bg-fg/5'"
        @click="sync.setMode(m)"
      >
        {{ t('deviceSync.mode.' + m) }}
      </button>
    </div>

    <div v-if="sync.config.mode === 'selected'" class="space-y-1">
      <div class="label">{{ t('deviceSync.choosePlaylists') }}</div>
      <label v-for="p in playlists.mine" :key="p.id" class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-fg/5">
        <input type="checkbox" class="size-4 accent-[var(--accent)]" :checked="sync.config.playlistIds.includes(p.id)" @change="sync.togglePlaylist(p.id)" />
        <span class="truncate text-sm">{{ p.name }}</span>
        <span class="ms-auto text-xs text-subtle">{{ t('playlist.songs', { n: p.tracks_count ?? 0 }) }}</span>
      </label>
      <p v-if="!playlists.mine.length" class="text-sm text-subtle">{{ t('playlist.noneYet') }}</p>
    </div>

    <template v-if="sync.config.mode !== 'off'">
      <label class="flex cursor-pointer items-center justify-between gap-4">
        <div><div class="font-semibold">{{ t('deviceSync.wifiOnly') }}</div><div class="text-sm text-muted">{{ t('deviceSync.wifiOnlyHint') }}</div></div>
        <input v-model="sync.config.wifiOnly" type="checkbox" class="size-5 accent-[var(--accent)]" />
      </label>

      <div>
        <div class="mb-1.5 flex items-center gap-2 text-sm">
          <Loader2 v-if="active" class="size-4 animate-spin text-accent" />
          <span class="font-semibold">{{ t('deviceSync.progress', { n: count(sync.onDevice), total: count(total) }) }}</span>
          <span class="text-subtle">· {{ bytes(downloads.totalBytes) }} / ~{{ bytes(sync.targetBytes) }}</span>
          <span class="ms-auto font-semibold tabular">{{ percent }}%</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-fg/10">
          <div class="h-full rounded-full bg-accent transition-all" :style="{ width: `${percent}%` }" />
        </div>
        <p v-if="sync.paused" class="mt-2 text-sm text-amber-400">{{ t('deviceSync.paused.' + sync.paused) }}</p>
        <p v-else-if="active" class="mt-2 text-sm text-muted">{{ t('deviceSync.downloading', { n: count(downloads.queued) }) }}</p>
        <p v-if="sync.waitingForServer" class="mt-1 text-xs text-subtle">{{ t('deviceSync.waitingForServer', { n: count(sync.waitingForServer) }) }}</p>
      </div>

      <div class="flex flex-wrap gap-2">
        <button v-if="sync.paused === 'manual'" class="btn-primary h-9 px-4" @click="sync.resume()"><Play class="size-4" />{{ t('deviceSync.resume') }}</button>
        <button v-else class="btn-ghost h-9 px-4" @click="sync.pause()"><Pause class="size-4" />{{ t('deviceSync.pause') }}</button>
        <button v-if="sync.config.mode !== 'all'" class="btn-ghost h-9 px-4" @click="removeOutside"><Trash2 class="size-4" />{{ t('deviceSync.removeOutside') }}</button>
      </div>
    </template>
  </section>
</template>
