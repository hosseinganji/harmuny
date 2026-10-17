<script setup lang="ts">
import { ChevronDown, Download, Heart, ListMusic, MicVocal, MoreHorizontal } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { duration } from '@/lib/format'
import { t } from '@/lib/i18n'
import { useDownloads } from '@/stores/downloads'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'
import LyricsView from './LyricsView.vue'
import PlaybackOptions from './PlaybackOptions.vue'
import PlayerControls from './PlayerControls.vue'
import QueuePanel from './QueuePanel.vue'
import SeekBar from './SeekBar.vue'
import TrackCover from './TrackCover.vue'
import VolumeControl from './VolumeControl.vue'

const player = usePlayer()
const library = useLibrary()
const downloads = useDownloads()
const ui = useUi()

const tab = ref<'art' | 'lyrics' | 'queue'>('art')
const desktopTab = ref<'lyrics' | 'queue'>('lyrics')
const preview = ref<number | null>(null)

watch(() => ui.nowPlayingOpen, (open) => {
  if (open) tab.value = 'art'
})

// Drag the sheet down to dismiss it.
const dragY = ref(0)
let startY = 0
let dragging = false
function touchStart(e: TouchEvent) {
  startY = e.touches[0].clientY
  dragging = true
}
function touchMove(e: TouchEvent) {
  if (!dragging) return
  isDragging.value = true
  dragY.value = Math.max(0, e.touches[0].clientY - startY)
}
const isDragging = ref(false)
function touchEnd() {
  dragging = false
  isDragging.value = false
  // Closing keeps the dragged offset so the sheet carries on down from where it was let go.
  if (dragY.value > 120) ui.nowPlayingOpen = false
  else dragY.value = 0
}
watch(() => ui.nowPlayingOpen, (open) => open && (dragY.value = 0))

const sheetStyle = computed(() => ({ '--drag': `${dragY.value}px`, ...(isDragging.value ? { transition: 'none' } : {}) }))
const downloadState = computed(() => {
  const tr = player.track
  if (!tr || tr.kind !== 'remote') return null
  return downloads.sizes.has(tr.id) ? 'done' : downloads.progress.has(tr.id) ? 'busy' : 'none'
})

function more(e: MouseEvent) {
  if (player.track) ui.openTrackMenu(player.track, e, { queueUid: player.current?.uid })
}
</script>

<template>
  <!-- On phones the player is a sheet covering 90% of the screen, like the other sheets; full screen on desktop. -->
  <Transition name="fade">
    <div v-if="ui.nowPlayingOpen && player.track" class="fixed inset-0 z-50 bg-black/50 lg:hidden" @click="ui.nowPlayingOpen = false" />
  </Transition>
  <Transition name="sheet">
    <div
      v-if="ui.nowPlayingOpen && player.track"
      class="np-sheet fixed inset-x-0 bottom-0 top-[10dvh] z-50 flex flex-col overflow-hidden rounded-t-[2rem] bg-bg text-fg shadow-[0_-20px_60px_rgba(0,0,0,0.5)] lg:top-0 lg:rounded-none"
      :style="sheetStyle"
      role="dialog"
      :aria-label="t('player.nowPlaying')"
    >
      <!-- Artwork-tinted backdrop -->
      <div class="pointer-events-none absolute inset-0">
        <img v-if="player.coverSrc" :src="player.coverSrc" class="size-full scale-125 object-cover opacity-50 blur-3xl" alt="" />
        <div class="absolute inset-0" style="background: linear-gradient(180deg, rgb(var(--dyn) / 0.55), color-mix(in oklab, var(--bg) 85%, transparent) 60%, var(--bg))" />
      </div>

      <div class="relative flex min-h-0 flex-1 flex-col pb-safe lg:pt-safe">
        <div class="mx-auto mt-2 h-1 w-10 shrink-0 rounded-full bg-fg/25 lg:hidden" @touchstart.passive="touchStart" @touchmove.passive="touchMove" @touchend="touchEnd" />
        <header class="flex shrink-0 items-center gap-2 px-4 py-3" @touchstart.passive="touchStart" @touchmove.passive="touchMove" @touchend="touchEnd">
          <button class="icon-btn text-fg" :aria-label="t('common.close')" @click="ui.nowPlayingOpen = false">
            <ChevronDown class="size-7" />
          </button>
          <div class="min-w-0 flex-1 text-center">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-muted">{{ player.context?.name ? t('queue.playingFrom') : t('player.nowPlaying') }}</div>
            <div class="truncate text-sm font-bold">{{ player.context?.name }}</div>
          </div>
          <button class="icon-btn text-fg" :aria-label="t('track.more')" @click="more">
            <MoreHorizontal class="size-6" />
          </button>
        </header>

        <div class="mx-auto flex w-full max-w-7xl flex-1 gap-10 overflow-hidden px-6 lg:px-12">
          <!-- Main column -->
          <div class="flex min-w-0 flex-1 flex-col lg:max-w-xl lg:justify-center">
            <!-- A size container: the artwork is the largest square that fits, so it can never spill onto the title. -->
            <div class="relative my-4 flex min-h-0 flex-1 items-center justify-center [container-type:size]" @touchstart.passive="tab === 'art' && touchStart($event)" @touchmove.passive="tab === 'art' && touchMove($event)" @touchend="tab === 'art' && touchEnd()">
              <div v-if="tab === 'art'" class="size-[min(100cqw,100cqh)] lg:max-h-112 lg:max-w-112">
                <TrackCover
                  :track="player.track"
                  size="fill"
                  rounded="rounded-3xl"
                  class="shadow-[0_30px_80px_-20px_rgba(0,0,0,0.7)] transition-transform duration-500"
                  :class="player.playing ? 'scale-100' : 'scale-[0.92]'"
                />
              </div>
              <LyricsView v-else-if="tab === 'lyrics'" big class="absolute inset-0" />
              <div v-else class="absolute inset-0 overflow-y-auto">
                <QueuePanel compact />
              </div>
            </div>

            <div class="shrink-0 space-y-4 pb-4">
              <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                  <h2 class="truncate text-2xl font-extrabold sm:text-3xl">{{ player.track.title }}</h2>
                  <RouterLink
                    v-if="player.track.artist && player.track.kind === 'remote'"
                    :to="{ name: 'artist', query: { name: player.track.artist } }"
                    class="block truncate text-lg text-muted hover:text-fg"
                    @click="ui.nowPlayingOpen = false"
                  >{{ player.track.artist }}</RouterLink>
                  <p v-else class="truncate text-lg text-muted">{{ player.track.artist ?? t('track.unknownArtist') }}</p>
                </div>
                <button
                  v-if="downloadState"
                  class="icon-btn size-11"
                  :class="{ '!text-accent': downloadState === 'done', 'animate-pulse': downloadState === 'busy' }"
                  :aria-label="t('track.download')"
                  @click="downloadState === 'none' && downloads.download(player.track)"
                >
                  <Download class="size-6" />
                </button>
                <button
                  v-if="player.track.kind === 'remote'"
                  class="icon-btn size-11"
                  :class="{ '!text-accent': library.isLiked(player.track) }"
                  :aria-label="t('track.like')"
                  @click="library.toggleLike(player.track)"
                >
                  <Heart class="size-7" :class="{ 'fill-current': library.isLiked(player.track) }" />
                </button>
              </div>

              <div>
                <SeekBar
                  thick
                  :value="player.progress"
                  :buffered="player.duration ? player.buffered / player.duration : 0"
                  :label="t('player.seek')"
                  @seek="(f) => player.seek(f * player.duration)"
                  @preview="(f) => (preview = f)"
                />
                <div dir="ltr" class="flex justify-between text-xs text-muted tabular">
                  <span>{{ duration(preview !== null ? preview * player.duration : player.currentTime) }}</span>
                  <span>-{{ duration(Math.max(0, player.duration - (preview !== null ? preview * player.duration : player.currentTime))) }}</span>
                </div>
              </div>

              <PlayerControls large />

              <div class="flex items-center justify-between">
                <button class="icon-btn size-11 lg:hidden" :class="{ '!text-accent': tab === 'lyrics' }" :aria-label="t('player.lyrics')" @click="tab = tab === 'lyrics' ? 'art' : 'lyrics'">
                  <MicVocal class="size-5.5" />
                </button>
                <div class="hidden lg:block"><VolumeControl /></div>
                <PlaybackOptions large />
                <button class="icon-btn size-11 lg:hidden" :class="{ '!text-accent': tab === 'queue' }" :aria-label="t('player.queue')" @click="tab = tab === 'queue' ? 'art' : 'queue'">
                  <ListMusic class="size-5.5" />
                </button>
              </div>
            </div>
          </div>

          <!-- Desktop side panel -->
          <aside class="hidden min-w-0 flex-1 flex-col py-4 lg:flex">
            <div class="mb-4 flex gap-2">
              <button class="chip" :class="{ 'chip-active': desktopTab === 'lyrics' }" @click="desktopTab = 'lyrics'">
                <MicVocal class="size-4" />{{ t('player.lyrics') }}
              </button>
              <button class="chip" :class="{ 'chip-active': desktopTab === 'queue' }" @click="desktopTab = 'queue'">
                <ListMusic class="size-4" />{{ t('player.queue') }}
              </button>
            </div>
            <div class="relative min-h-0 flex-1 overflow-hidden rounded-3xl bg-black/15 p-6">
              <LyricsView v-if="desktopTab === 'lyrics'" big />
              <div v-else class="h-full overflow-y-auto"><QueuePanel /></div>
            </div>
          </aside>
        </div>
      </div>
    </div>
  </Transition>
</template>
