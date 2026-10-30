<script setup lang="ts">
import { useLikePop } from '@/lib/motion'
import { ChevronDown, Download, Heart, ListMusic, Loader2, MicVocal, MoreHorizontal, UsersRound } from 'lucide-vue-next'
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { duration } from '@/lib/format'
import { t } from '@/lib/i18n'
import { useDownloads } from '@/stores/downloads'
import { useJam } from '@/stores/jam'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'
import JamReactionBar from './JamReactionBar.vue'
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
const jam = useJam()
const router = useRouter()

/** The Jam button: open the Jam, or start one with what is playing now. */
const startingJam = ref(false)
async function openJam() {
  if (!jam.active) {
    startingJam.value = true
    try {
      await jam.start()
    } catch {
      return ui.toast(t('jam.startFailed'), 'error')
    } finally {
      startingJam.value = false
    }
  }
  ui.nowPlayingOpen = false
  router.push('/jam')
}

const tab = ref<'art' | 'lyrics' | 'queue'>('art')
const desktopTab = ref<'lyrics' | 'queue'>('lyrics')
const preview = ref<number | null>(null)

// The sheet stays rendered below the screen while a song is loaded, so opening it is only a slide (nothing
// to build first), moved on the GPU. A drag moves it with the finger, with no re-rendering; let go, it
// glides back up or carries on down at the speed of the flick.
const sheet = ref<HTMLElement>()
const shade = ref<HTMLElement>()
const shown = ref(false)
const EASE = 'cubic-bezier(0.32, 0.72, 0, 1)'
let closeMs = 300

const height = () => sheet.value?.offsetHeight || innerHeight

function place(y: number, ms: number) {
  const el = sheet.value
  if (!el) return
  el.style.transition = ms ? `transform ${ms}ms ${EASE}` : 'none'
  el.style.transform = `translate3d(0, ${y}px, 0)`
  if (shade.value) {
    shade.value.style.transition = ms ? `opacity ${ms}ms linear` : 'none'
    shade.value.style.opacity = String(Math.max(0, 1 - y / height()))
  }
}

watch(() => ui.nowPlayingOpen, async (open) => {
  if (open) {
    tab.value = 'art'
    shown.value = true
    await nextTick()
    place(height(), 0)
    // Two frames: the start position is painted before the slide begins.
    requestAnimationFrame(() => requestAnimationFrame(() => ui.nowPlayingOpen && place(0, 420)))
  } else if (shown.value) {
    place(height(), closeMs)
    closeMs = 300
  }
})

function slid(e: TransitionEvent) {
  if (e.target === sheet.value && e.propertyName === 'transform' && !ui.nowPlayingOpen) shown.value = false
}

let startY = 0
let lastY = 0
let lastT = 0
let velocity = 0
let dy = 0
let dragging = false
function touchStart(e: TouchEvent) {
  dragging = true
  startY = lastY = e.touches[0].clientY
  lastT = e.timeStamp
  velocity = 0
  dy = 0
}
function touchMove(e: TouchEvent) {
  if (!dragging) return
  const y = e.touches[0].clientY
  const dt = e.timeStamp - lastT
  if (dt > 0) velocity = 0.8 * ((y - lastY) / dt) + 0.2 * velocity
  lastY = y
  lastT = e.timeStamp
  dy = Math.max(0, y - startY)
  place(dy, 0)
}
function touchEnd() {
  if (!dragging) return
  dragging = false
  if (dy < 4) return
  const h = height()
  if (dy > h * 0.22 || velocity > 0.45) {
    // Carry on at the speed of the flick (never slower than a normal close).
    closeMs = Math.round(Math.min(320, Math.max(150, (h - dy) / Math.max(velocity, 1.4))))
    ui.nowPlayingOpen = false
  } else {
    place(0, 340)
  }
}
const downloadState = computed(() => {
  const tr = player.track
  if (!tr || tr.kind !== 'remote') return null
  return downloads.sizes.has(tr.id) ? 'done' : downloads.progress.has(tr.id) ? 'busy' : 'none'
})

function more(e: MouseEvent) {
  if (player.track) ui.openTrackMenu(player.track, e, { queueUid: player.current?.uid })
}
const likePop = useLikePop(() => !!player.track && library.isLiked(player.track))
</script>

<template>
  <!-- On phones the player is a sheet covering 90% of the screen, like the other sheets; full screen on desktop. -->
  <template v-if="player.track">
    <div ref="shade" class="fixed inset-0 z-50 bg-black/50 opacity-0 lg:hidden" :class="{ invisible: !shown }" @click="ui.nowPlayingOpen = false" />
    <div
      ref="sheet"
      class="np-sheet fixed inset-x-0 bottom-0 top-[10dvh] z-50 flex flex-col overflow-hidden rounded-t-[2rem] bg-bg text-fg shadow-[0_-20px_60px_rgba(0,0,0,0.5)] lg:top-0 lg:rounded-none"
      :class="{ invisible: !shown }"
      role="dialog"
      :aria-label="t('player.nowPlaying')"
      :aria-hidden="!shown"
      @transitionend="slid"
    >
      <!-- Artwork-tinted backdrop: a tiny, lightly blurred copy blown up (the same look as a big blur, at a fraction of the cost). -->
      <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <img v-if="player.coverSrc" :src="player.coverSrc" class="absolute left-1/2 top-1/2 size-12 -translate-x-1/2 -translate-y-1/2 scale-[16] object-cover opacity-50 blur-[3px]" alt="" />
        <div class="absolute inset-0" style="background: linear-gradient(180deg, rgb(var(--dyn) / 0.55), color-mix(in oklab, var(--bg) 85%, transparent) 60%, var(--bg))" />
      </div>

      <div class="relative flex min-h-0 flex-1 flex-col pb-safe lg:pt-safe">
        <div class="mx-auto mt-2 h-1 w-10 shrink-0 rounded-full bg-fg/25 lg:hidden" @touchstart.passive="touchStart" @touchmove.passive="touchMove" @touchend="touchEnd" />
        <header class="flex shrink-0 items-center gap-2 px-4 py-3" @touchstart.passive="touchStart" @touchmove.passive="touchMove" @touchend="touchEnd">
          <button class="icon-btn text-fg" :aria-label="t('common.close')" @click="ui.nowPlayingOpen = false">
            <ChevronDown class="size-7" />
          </button>
          <button v-if="jam.active" class="min-w-0 flex-1 text-center" @click="openJam">
            <div class="flex items-center justify-center gap-1.5 text-[11px] font-extrabold uppercase tracking-widest text-accent">
              <span class="size-1.5 animate-pulse rounded-full bg-accent" />{{ t('jam.title') }} · {{ t('jam.listeners', { n: jam.members.filter((m) => m.online).length || 1 }) }}
            </div>
            <div class="truncate text-sm font-bold">{{ t('jam.hostJam', { name: jam.host?.name ?? '' }) }}</div>
          </button>
          <div v-else class="min-w-0 flex-1 text-center">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-muted">{{ player.context?.name ? t('queue.playingFrom') : t('player.nowPlaying') }}</div>
            <div class="truncate text-sm font-bold">{{ player.context?.name }}</div>
          </div>
          <!-- Listen together: start a Jam with this song, or open the one going on. -->
          <button
            class="icon-btn text-fg"
            :class="{ '!bg-accent !text-white': jam.active }"
            :aria-label="jam.active ? t('jam.open') : t('jam.start')"
            :title="jam.active ? t('jam.open') : t('jam.start')"
            :disabled="startingJam"
            @click="openJam"
          >
            <Loader2 v-if="startingJam" class="size-5 animate-spin" />
            <UsersRound v-else class="size-5.5" />
          </button>
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
                  class="shadow-[0_30px_80px_-20px_rgba(0,0,0,0.7)] transition-transform duration-300"
                  :class="player.playing ? 'scale-100' : 'scale-[0.94]'"
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
                  <Heart class="size-7" :class="{ 'fill-current': library.isLiked(player.track), 'heart-pop': likePop }" />
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

              <JamReactionBar v-if="jam.active" compact />

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
  </template>
</template>
