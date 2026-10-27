<script setup lang="ts">
import { Heart, ListMusic, Maximize2, MicVocal, MonitorSpeaker, UsersRound } from 'lucide-vue-next'
import { ref } from 'vue'
import { duration } from '@/lib/format'
import { t } from '@/lib/i18n'
import { useJam } from '@/stores/jam'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'
import PlaybackOptions from './PlaybackOptions.vue'
import PlayerControls from './PlayerControls.vue'
import SeekBar from './SeekBar.vue'
import TrackCover from './TrackCover.vue'
import VolumeControl from './VolumeControl.vue'

const player = usePlayer()
const library = useLibrary()
const ui = useUi()
const jam = useJam()
const preview = ref<number | null>(null)
</script>

<template>
  <footer v-if="player.track" class="glass relative z-30 hidden h-22 items-center gap-4 border-t border-line px-4 lg:flex">
    <!-- Now playing -->
    <div class="flex w-[30%] min-w-0 items-center gap-3">
      <button class="group relative" :aria-label="t('player.expand')" @click="ui.nowPlayingOpen = true">
        <TrackCover :track="player.track" size="md" rounded="rounded-md" class="!size-14 shadow-lg" />
        <span class="absolute inset-0 flex items-center justify-center rounded-md bg-black/50 opacity-0 transition group-hover:opacity-100">
          <Maximize2 class="size-5 text-white" />
        </span>
      </button>
      <div class="min-w-0">
        <div class="truncate font-semibold">{{ player.track.title }}</div>
        <RouterLink
          v-if="player.track.artist && player.track.kind === 'remote'"
          :to="{ name: 'artist', query: { name: player.track.artist } }"
          class="block truncate text-sm text-muted hover:text-fg hover:underline"
        >{{ player.track.artist }}</RouterLink>
        <div v-else class="truncate text-sm text-muted">{{ player.track.artist ?? t('track.unknownArtist') }}</div>
      </div>
      <button
        v-if="player.track.kind === 'remote'"
        class="icon-btn size-9 shrink-0"
        :class="{ '!text-accent': library.isLiked(player.track) }"
        :aria-label="t('track.like')"
        @click="library.toggleLike(player.track)"
      >
        <Heart class="size-4.5" :class="{ 'fill-current': library.isLiked(player.track) }" />
      </button>
    </div>

    <!-- Transport -->
    <div class="flex max-w-2xl flex-1 flex-col items-center gap-1">
      <PlayerControls />
      <div dir="ltr" class="flex w-full items-center gap-2 text-xs text-subtle tabular">
        <span class="w-10 text-end">{{ duration(preview !== null ? preview * player.duration : player.currentTime) }}</span>
        <SeekBar
          class="flex-1"
          :value="player.progress"
          :buffered="player.duration ? player.buffered / player.duration : 0"
          :label="t('player.seek')"
          @seek="(f) => player.seek(f * player.duration)"
          @preview="(f) => (preview = f)"
        />
        <span class="w-10">{{ duration(player.duration) }}</span>
      </div>
    </div>

    <!-- Extras -->
    <div class="flex w-[30%] items-center justify-end gap-1">
      <PlaybackOptions />
      <RouterLink to="/jam" class="icon-btn size-9" :class="{ '!bg-accent !text-white': jam.active }" :aria-label="jam.active ? t('jam.open') : t('jam.start')" :title="jam.active ? t('jam.open') : t('jam.start')">
        <UsersRound class="size-4.5" />
      </RouterLink>
      <button class="icon-btn size-9" :class="{ '!text-accent': ui.sidePanel === 'lyrics' }" :aria-label="t('player.lyrics')" @click="ui.toggleSidePanel('lyrics')">
        <MicVocal class="size-4.5" />
      </button>
      <button class="icon-btn size-9" :class="{ '!text-accent': ui.sidePanel === 'queue' }" :aria-label="t('player.queue')" @click="ui.toggleSidePanel('queue')">
        <ListMusic class="size-4.5" />
      </button>
      <VolumeControl />
      <button class="icon-btn size-9 xl:hidden" :aria-label="t('player.expand')" @click="ui.nowPlayingOpen = true">
        <MonitorSpeaker class="size-4.5" />
      </button>
    </div>
  </footer>
</template>
