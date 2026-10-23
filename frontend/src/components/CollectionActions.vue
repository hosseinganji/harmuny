<script setup lang="ts">
import { ArrowDownToLine, CheckCircle2, Loader2, Pause, Play, Shuffle } from 'lucide-vue-next'
import { computed } from 'vue'
import { num } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { Track } from '@/lib/types'
import { useDownloads } from '@/stores/downloads'
import { type PlayContext, usePlayer } from '@/stores/player'

const props = withDefaults(defineProps<{ tracks: Track[]; context: PlayContext; downloadable?: boolean }>(), { downloadable: true })
const player = usePlayer()
const downloads = useDownloads()

const remote = computed(() => props.tracks.filter((t) => t.kind === 'remote'))
const downloaded = computed(() => remote.value.filter((t) => downloads.isDownloaded(t)).length)
const busy = computed(() => remote.value.some((t) => downloads.isDownloading(t)))
const isThisPlaying = computed(() => player.context?.type === props.context.type && player.context?.id === props.context.id && player.context?.name === props.context.name)

function playAll() {
  if (isThisPlaying.value) return player.toggle()
  player.playTracks(props.tracks, 0, { context: props.context, shuffle: false })
}
function shuffleAll() {
  player.playTracks(props.tracks, -1, { context: props.context, shuffle: true })
}
</script>

<template>
  <!-- The round buttons never shrink (squeezed, they turn into ovals); the extra icons on the end stay compact. -->
  <div class="flex items-center gap-2.5 px-4 py-4 sm:gap-3 sm:px-6 [&>button]:shrink-0">
    <button
      class="flex size-14 items-center justify-center rounded-full bg-accent text-accent-fg shadow-xl transition hover:scale-105 active:scale-95 disabled:opacity-40"
      style="box-shadow: 0 10px 30px -10px var(--accent)"
      :disabled="!tracks.length"
      :aria-label="t('player.play')"
      @click="playAll"
    >
      <Pause v-if="isThisPlaying && player.playing" class="size-6 fill-current" />
      <Play v-else class="size-6 translate-x-[1px] fill-current" />
    </button>
    <button class="liquid icon-btn size-11 text-fg sm:size-12" :disabled="!tracks.length" :aria-label="t('player.shuffle')" @click="shuffleAll">
      <Shuffle class="size-6" />
    </button>
    <button
      v-if="downloadable && remote.length"
      class="liquid icon-btn size-11 text-fg sm:size-12"
      :class="{ '!text-accent': downloaded === remote.length }"
      :aria-label="t('menu.download')"
      :title="t('collection.downloaded', { n: downloaded, total: remote.length })"
      @click="downloaded < remote.length && downloads.download(remote)"
    >
      <Loader2 v-if="busy" class="size-6 animate-spin" />
      <CheckCircle2 v-else-if="downloaded === remote.length" class="size-6" />
      <ArrowDownToLine v-else class="size-6" />
    </button>
    <span v-if="downloadable && downloaded && downloaded < remote.length" class="hidden text-xs text-subtle tabular min-[400px]:inline">{{ num(downloaded) }}/{{ num(remote.length) }}</span>
    <div class="ms-auto flex min-w-0 items-center gap-0.5 sm:gap-2 [&>.icon-btn]:size-9 sm:[&>.icon-btn]:size-10"><slot /></div>
  </div>
</template>
