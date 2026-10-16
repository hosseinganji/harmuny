<script setup lang="ts">
import { ArrowDownCircle, CheckCircle2, Heart, MoreHorizontal, Pause, Play } from 'lucide-vue-next'
import { computed } from 'vue'
import { duration, num } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { Track } from '@/lib/types'
import { useDownloads } from '@/stores/downloads'
import { useLibrary } from '@/stores/library'
import { trackKey, usePlayer } from '@/stores/player'
import PlayingBars from './PlayingBars.vue'
import TrackCover from './TrackCover.vue'

const props = defineProps<{ track: Track; index?: number; showAlbum?: boolean; numbered?: boolean }>()
const emit = defineEmits<{ play: []; menu: [event: MouseEvent] }>()

const player = usePlayer()
const library = useLibrary()
const downloads = useDownloads()

const isCurrent = computed(() => player.track && trackKey(player.track) === trackKey(props.track))
// Lists can hold an older copy; the catalog has the latest edit.
const shown = computed(() => (props.track.kind === 'remote' ? library.get(props.track.id) ?? props.track : props.track))
const progress = computed(() => (props.track.kind === 'remote' ? downloads.progress.get(props.track.id) : undefined))

function onClick() {
  if (isCurrent.value) player.toggle()
  else emit('play')
}

let pressTimer: ReturnType<typeof setTimeout> | undefined
function onTouchStart(e: TouchEvent) {
  const touch = e.touches[0]
  pressTimer = setTimeout(() => emit('menu', { clientX: touch.clientX, clientY: touch.clientY } as MouseEvent), 500)
}
function cancelPress() {
  clearTimeout(pressTimer)
}
</script>

<template>
  <div
    class="group relative flex h-15 cursor-pointer select-none items-center gap-3 rounded-xl px-2 transition-colors hover:bg-fg/5 sm:px-3"
    :class="{ 'bg-fg/[0.04]': isCurrent }"
    @click="onClick"
    @contextmenu.prevent="emit('menu', $event)"
    @touchstart.passive="onTouchStart"
    @touchend="cancelPress"
    @touchmove="cancelPress"
  >
    <div v-if="numbered" class="hidden w-6 shrink-0 text-center text-sm text-subtle tabular sm:block">
      <PlayingBars v-if="isCurrent" :paused="!player.playing" />
      <span v-else>{{ num((index ?? 0) + 1) }}</span>
    </div>

    <TrackCover :track="track" size="md">
      <div
        class="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity group-hover:opacity-100"
        :class="{ 'opacity-100': isCurrent && !numbered }"
      >
        <template v-if="isCurrent">
          <Pause v-if="player.playing" class="size-5 fill-white text-white" />
          <Play v-else class="size-5 fill-white text-white" />
        </template>
        <Play v-else class="size-5 fill-white text-white" />
      </div>
    </TrackCover>

    <div class="min-w-0 flex-1">
      <div class="truncate font-medium leading-tight" :class="isCurrent ? 'text-accent' : 'text-fg'">{{ shown.title }}</div>
      <div class="mt-0.5 flex items-center gap-1.5 truncate text-sm text-muted">
        <CheckCircle2 v-if="track.kind === 'remote' && downloads.sizes.has(track.id)" class="size-3.5 shrink-0 text-accent" />
        <span v-if="track.kind === 'local'" class="rounded bg-fg/10 px-1 text-[10px] font-semibold uppercase">{{ t('local.badge') }}</span>
        <span class="truncate">{{ shown.artist ?? t('track.unknownArtist') }}</span>
      </div>
    </div>

    <div v-if="showAlbum" class="hidden w-1/4 truncate text-sm text-muted lg:block">{{ track.album }}</div>

    <div v-if="progress !== undefined" class="flex items-center gap-1 text-xs text-accent tabular">
      <ArrowDownCircle class="size-4 animate-pulse" />{{ num(Math.round(progress * 100)) }}%
    </div>

    <button
      v-if="track.kind === 'remote'"
      class="icon-btn hidden size-9 sm:inline-flex"
      :class="library.isLiked(track) ? 'text-accent' : 'opacity-0 group-hover:opacity-100'"
      :aria-label="t('track.like')"
      @click.stop="library.toggleLike(track)"
    >
      <Heart class="size-4.5" :class="{ 'fill-current': library.isLiked(track) }" />
    </button>

    <div class="hidden w-12 text-end text-sm text-subtle tabular sm:block">{{ duration(track.duration) }}</div>

    <button class="icon-btn size-9 shrink-0" :aria-label="t('track.more')" @click.stop="emit('menu', $event)">
      <MoreHorizontal class="size-5" />
    </button>
  </div>
</template>
