<script setup lang="ts">
import { CloudUpload, ExternalLink, HardDrive, Radar, Send } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { bytes, count, duration, relativeTime } from '@/lib/format'
import { t } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import Avatar from './Avatar.vue'
import Modal from './Modal.vue'
import TrackCover from './TrackCover.vue'

/** Everything about a song: where it came from and who brought it, its quality, and how it is doing. */
interface Details {
  source: 'telegram' | 'upload' | 'discovery'
  source_url: string | null
  channel: { title: string; username: string | null } | null
  found_in: string | null
  added_by: { id: number; name: string; avatar_url: string | null } | null
  added_at: string | null
  posted_at: string | null
  format: string | null
  bitrate: number | null
  file_size: number
  duration: number
  on_server: boolean
  play_count: number
  listeners: number
  likes: number
  followers: number
}

const ui = useUi()
const details = ref<Details | null>(null)
const failed = ref(false)
const track = computed(() => ui.trackInfo)

watch(track, async (tr) => {
  details.value = null
  failed.value = false
  if (!tr || tr.kind !== 'remote') return
  try {
    details.value = await api.get<Details>(`/tracks/${tr.id}/details`)
  } catch {
    failed.value = true
  }
})

const sourceIcon = computed(() => ({ telegram: Send, upload: CloudUpload, discovery: Radar })[details.value?.source ?? 'telegram'])
const quality = computed(() => {
  const d = details.value
  if (!d?.bitrate) return null
  return d.bitrate >= 300 ? t('info.qualityHigh') : d.bitrate >= 190 ? t('info.qualityGood') : t('info.qualityNormal')
})
</script>

<template>
  <Modal :open="!!track" :title="t('info.title')" @close="ui.trackInfo = null">
    <template v-if="track">
      <div class="flex items-center gap-4">
        <TrackCover :track="track" size="xl" rounded="rounded-2xl" class="!size-24 shadow-xl" />
        <div class="min-w-0">
          <div class="truncate text-xl font-black">{{ track.title }}</div>
          <div class="truncate text-muted">{{ track.artist ?? t('track.unknownArtist') }}</div>
          <div v-if="track.album || track.year" class="truncate text-sm text-subtle">{{ [track.album, track.year].filter(Boolean).join(' · ') }}</div>
        </div>
      </div>

      <!-- Where it came from -->
      <section v-if="details" class="mt-5 rounded-2xl bg-fg/[0.05] p-4">
        <div class="flex items-center gap-3">
          <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-accent/15 text-accent"><component :is="sourceIcon" class="size-5" /></span>
          <div class="min-w-0 flex-1">
            <div class="text-xs font-bold uppercase tracking-wider text-subtle">{{ t('info.source') }}</div>
            <div class="truncate font-bold">
              <template v-if="details.source === 'upload'">{{ t('info.uploaded') }}</template>
              <template v-else-if="details.source === 'discovery'">{{ t('info.discovered', { channel: '@' + (details.found_in ?? '') }) }}</template>
              <template v-else>{{ t('info.telegram', { channel: details.channel?.title ?? '' }) }}</template>
            </div>
          </div>
          <a v-if="details.source_url" :href="details.source_url" target="_blank" rel="noopener" class="chip h-9 shrink-0 text-xs">
            <ExternalLink class="size-3.5" />{{ t('info.openPost') }}
          </a>
        </div>
        <div v-if="details.added_by" class="mt-3 flex items-center gap-2 text-sm">
          <Avatar :name="details.added_by.name" :src="details.added_by.avatar_url" size="size-7 text-[10px]" />
          <span class="text-muted">{{ t('info.addedBy') }}</span><span class="font-semibold">{{ details.added_by.name }}</span>
        </div>
        <div class="mt-2 text-sm text-muted">
          {{ t('info.addedAt', { when: relativeTime(details.added_at) }) }}
          <template v-if="details.posted_at && details.source !== 'upload'"> · {{ t('info.postedAt', { when: relativeTime(details.posted_at) }) }}</template>
        </div>
      </section>

      <!-- The file and how it is doing -->
      <dl v-if="details" class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
        <div class="rounded-2xl bg-fg/[0.05] p-3">
          <dt class="text-xs text-subtle">{{ t('info.quality') }}</dt>
          <dd class="font-bold" dir="ltr">{{ details.bitrate ? `${count(details.bitrate)} kbps` : '—' }}</dd>
          <dd v-if="quality" class="text-xs text-accent">{{ quality }}</dd>
        </div>
        <div class="rounded-2xl bg-fg/[0.05] p-3">
          <dt class="text-xs text-subtle">{{ t('info.file') }}</dt>
          <dd class="font-bold uppercase" dir="ltr">{{ details.format ?? '—' }}</dd>
          <dd class="text-xs text-muted">{{ bytes(details.file_size) }} · {{ duration(details.duration) }}</dd>
        </div>
        <div class="rounded-2xl bg-fg/[0.05] p-3">
          <dt class="text-xs text-subtle">{{ t('info.storage') }}</dt>
          <dd class="flex items-center gap-1 font-bold"><HardDrive class="size-3.5" />{{ details.on_server ? t('info.onServer') : t('info.onTelegram') }}</dd>
        </div>
        <div class="rounded-2xl bg-fg/[0.05] p-3">
          <dt class="text-xs text-subtle">{{ t('info.plays') }}</dt>
          <dd class="font-bold">{{ count(details.play_count) }}</dd>
          <dd class="text-xs text-muted">{{ t('info.listeners', { n: details.listeners }) }}</dd>
        </div>
        <div class="rounded-2xl bg-fg/[0.05] p-3">
          <dt class="text-xs text-subtle">{{ t('info.likes') }}</dt>
          <dd class="font-bold">{{ count(details.likes) }}</dd>
        </div>
        <div class="rounded-2xl bg-fg/[0.05] p-3">
          <dt class="text-xs text-subtle">{{ t('info.followers') }}</dt>
          <dd class="font-bold">{{ count(details.followers) }}</dd>
        </div>
      </dl>
      <div v-else-if="track.kind === 'remote' && !failed" class="mt-5 space-y-2">
        <div v-for="i in 3" :key="i" class="skeleton h-16 rounded-2xl" />
      </div>
      <p v-else-if="track.kind === 'local'" class="mt-5 rounded-2xl bg-fg/[0.05] p-4 text-sm text-muted">{{ t('info.local', { file: track.file_name }) }}</p>
      <p v-else class="mt-5 text-sm text-muted">{{ t('toast.offlineAction') }}</p>
    </template>
  </Modal>
</template>
