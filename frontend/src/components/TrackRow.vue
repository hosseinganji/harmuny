<script setup lang="ts">
import { useLikePop } from '@/lib/motion'
import { ArrowDownCircle, CheckCircle2, Headphones, Heart, ListEnd, ListStart, MoreHorizontal, Pause, Play } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { count, duration, num } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { Track } from '@/lib/types'
import { useDownloads } from '@/stores/downloads'
import { useLibrary } from '@/stores/library'
import { trackKey, usePlayer } from '@/stores/player'
import Avatar from './Avatar.vue'
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
const plays = computed(() => library.playsOf(props.track))
const showPlays = computed(() => plays.value > library.playBadgeMin)
const progress = computed(() => (props.track.kind === 'remote' ? downloads.progress.get(props.track.id) : undefined))

function onClick() {
  if (swiped) return
  if (isCurrent.value) player.toggle()
  else emit('play')
}

// Swipe: left-to-right adds the song to the queue, right-to-left plays it next. Long-press opens the menu.
const SWIPE_TRIGGER = 80
const SWIPE_MAX = 130
const dx = ref(0)
const settling = ref(false)
let startX = 0
let startY = 0
let axis: 'x' | 'y' | null = null
let swiped = false
let pressTimer: ReturnType<typeof setTimeout> | undefined

function onTouchStart(e: TouchEvent) {
  const touch = e.touches[0]
  startX = touch.clientX
  startY = touch.clientY
  axis = null
  swiped = false
  settling.value = false
  pressTimer = setTimeout(() => emit('menu', { clientX: touch.clientX, clientY: touch.clientY } as MouseEvent), 500)
}

function onTouchMove(e: TouchEvent) {
  const touch = e.touches[0]
  const x = touch.clientX - startX
  const y = touch.clientY - startY
  if (Math.abs(x) > 8 || Math.abs(y) > 8) clearTimeout(pressTimer)
  // Decide once per gesture, so vertical scrolling never turns into a swipe.
  if (!axis && (Math.abs(x) > 10 || Math.abs(y) > 10)) axis = Math.abs(x) > Math.abs(y) * 1.3 ? 'x' : 'y'
  if (axis !== 'x') return
  // Past the trigger point the row resists, like iOS.
  const past = Math.max(0, Math.abs(x) - SWIPE_TRIGGER)
  dx.value = Math.sign(x) * Math.min(SWIPE_MAX, Math.min(Math.abs(x), SWIPE_TRIGGER) + past * 0.35)
}

function onTouchEnd() {
  clearTimeout(pressTimer)
  if (axis === 'x') {
    swiped = true
    if (dx.value >= SWIPE_TRIGGER) player.addToQueue(props.track)
    else if (dx.value <= -SWIPE_TRIGGER) player.playNext(props.track)
    if (Math.abs(dx.value) >= SWIPE_TRIGGER) navigator.vibrate?.(12)
    // The click that follows a swipe must not start playback.
    setTimeout(() => (swiped = false), 350)
  }
  settling.value = true
  dx.value = 0
  axis = null
}

const armed = computed(() => Math.abs(dx.value) >= SWIPE_TRIGGER)
const likePop = useLikePop(() => library.isLiked(props.track))
</script>

<template>
  <div class="relative overflow-hidden rounded-xl [touch-action:pan-y]">
    <!-- Revealed behind the row while it is swiped -->
    <div
      v-if="dx"
      class="absolute inset-0 flex items-center px-5 text-sm font-semibold text-white transition-colors"
      :class="[dx > 0 ? 'justify-start' : 'justify-end', armed ? (dx > 0 ? 'bg-accent' : 'bg-emerald-600') : 'bg-fg/15']"
      dir="ltr"
    >
      <span class="flex items-center gap-2 transition-transform duration-200" :class="{ 'scale-110': armed }">
        <template v-if="dx > 0"><ListEnd class="size-5" />{{ t('menu.addToQueue') }}</template>
        <template v-else>{{ t('menu.playNext') }}<ListStart class="size-5" /></template>
      </span>
    </div>
  <div
    class="group relative flex h-15 cursor-pointer select-none items-center gap-3 rounded-xl px-2 transition-colors hover:bg-fg/5 sm:px-3"
    :class="[{ 'bg-fg/[0.04]': isCurrent && !dx, '!bg-bg': dx }, settling ? 'transition-transform duration-300 ease-[var(--ease-sheet)]' : '']"
    :style="dx ? { transform: `translateX(${dx}px)` } : undefined"
    @click="onClick"
    @contextmenu.prevent="emit('menu', $event)"
    @touchstart.passive="onTouchStart"
    @touchmove.passive="onTouchMove"
    @touchend="onTouchEnd"
    @touchcancel="onTouchEnd"
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
        <!-- Shared playlists: who added the song -->
        <Avatar
          v-if="track.kind === 'remote' && track.added_by"
          :name="track.added_by.name"
          :src="track.added_by.avatar_url"
          size="size-4 text-[7px]"
          :title="t('collab.addedBy', { name: track.added_by.name })"
        />
        <span class="truncate">{{ shown.artist ?? t('track.unknownArtist') }}</span>
        <span v-if="showPlays" class="flex shrink-0 items-center gap-1 text-xs text-subtle tabular" :title="t('track.plays', { n: plays })">
          <span aria-hidden="true">·</span><Headphones class="size-3" />{{ count(plays) }}
        </span>
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
      <Heart class="size-4.5" :class="{ 'fill-current': library.isLiked(track), 'heart-pop': likePop }" />
    </button>

    <div class="hidden w-12 text-end text-sm text-subtle tabular sm:block">{{ duration(track.duration) }}</div>

    <button class="icon-btn size-9 shrink-0" :aria-label="t('track.more')" @click.stop="emit('menu', $event)">
      <MoreHorizontal class="size-5" />
    </button>
  </div>
</div>
</template>
