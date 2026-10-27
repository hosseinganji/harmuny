<script setup lang="ts">
import { useLikePop } from '@/lib/motion'
import { Heart, Loader2, Pause, Play, SkipForward, UsersRound } from 'lucide-vue-next'
import { t } from '@/lib/i18n'
import { useJam } from '@/stores/jam'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'
import TrackCover from './TrackCover.vue'

const player = usePlayer()
const library = useLibrary()
const ui = useUi()
const jam = useJam()

// Swipe left/right on the mini player to change tracks.
let startX = 0
function touchStart(e: TouchEvent) {
  startX = e.touches[0].clientX
}
function touchEnd(e: TouchEvent) {
  const dx = e.changedTouches[0].clientX - startX
  if (Math.abs(dx) > 70) dx < 0 ? player.next() : player.prev()
}
const likePop = useLikePop(() => !!player.track && library.isLiked(player.track))
</script>

<template>
  <div v-if="player.track" class="lg:hidden">
    <div
      class="liquid relative flex h-15 items-center gap-3 overflow-hidden rounded-full ps-1.5 pe-2"
      style="--lg-tint: color-mix(in oklab, rgb(var(--dyn) / 0.5) 45%, var(--lg-tint-strong))"
      @click="ui.nowPlayingOpen = true"
      @touchstart.passive="touchStart"
      @touchend="touchEnd"
    >
      <span class="relative shrink-0">
        <TrackCover :track="player.track" size="md" rounded="rounded-full" />
        <!-- In a Jam: listening together. -->
        <span v-if="jam.active" class="absolute -bottom-0.5 -end-0.5 flex size-5 items-center justify-center rounded-full bg-accent text-white ring-2 ring-bg"><UsersRound class="size-3" /></span>
      </span>
      <div class="min-w-0 flex-1">
        <div class="truncate text-sm font-semibold">{{ player.track.title }}</div>
        <div class="truncate text-xs text-muted">{{ player.track.artist ?? t('track.unknownArtist') }}</div>
      </div>
      <button
        v-if="player.track.kind === 'remote'"
        class="icon-btn"
        :class="{ '!text-accent': library.isLiked(player.track) }"
        :aria-label="t('track.like')"
        @click.stop="library.toggleLike(player.track)"
      >
        <Heart class="size-5" :class="{ 'fill-current': library.isLiked(player.track), 'heart-pop': likePop }" />
      </button>
      <button class="icon-btn text-fg" :aria-label="player.playing ? t('player.pause') : t('player.play')" @click.stop="player.toggle()">
        <Loader2 v-if="player.loading && player.playing" class="size-6 animate-spin" />
        <Pause v-else-if="player.playing" class="size-6 fill-current" />
        <Play v-else class="size-6 fill-current" />
      </button>
      <button class="icon-btn hidden text-fg sm:inline-flex" :aria-label="t('player.next')" @click.stop="player.next()">
        <SkipForward class="size-5 fill-current" />
      </button>
    </div>
  </div>
</template>
