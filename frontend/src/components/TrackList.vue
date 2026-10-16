<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { Track } from '@/lib/types'
import { type PlayContext, usePlayer } from '@/stores/player'
import { type TrackMenuContext, useUi } from '@/stores/ui'
import TrackRow from './TrackRow.vue'

const props = withDefaults(
  defineProps<{
    tracks: Track[]
    context?: PlayContext
    showAlbum?: boolean
    numbered?: boolean
    menuContext?: (track: Track) => TrackMenuContext
  }>(),
  { showAlbum: false, numbered: false },
)

const player = usePlayer()
const ui = useUi()

// Rows are a fixed 60px, so long lists render only what is on screen.
const ROW = 60
const OVERSCAN = 12
const VIRTUAL_FROM = 120

const root = ref<HTMLElement>()
const range = ref({ start: 0, end: VIRTUAL_FROM })
let scroller: HTMLElement | Window = window

const virtual = computed(() => props.tracks.length > VIRTUAL_FROM)
const visible = computed(() =>
  virtual.value
    ? props.tracks.slice(range.value.start, range.value.end).map((track, i) => ({ track, index: range.value.start + i }))
    : props.tracks.map((track, index) => ({ track, index })),
)

function measure() {
  if (!virtual.value || !root.value) return
  const rect = root.value.getBoundingClientRect()
  const viewTop = scroller instanceof Window ? 0 : scroller.getBoundingClientRect().top
  const viewHeight = scroller instanceof Window ? innerHeight : scroller.clientHeight
  const offset = viewTop - rect.top
  const start = Math.max(0, Math.floor(offset / ROW) - OVERSCAN)
  const end = Math.min(props.tracks.length, Math.ceil((offset + viewHeight) / ROW) + OVERSCAN)
  if (start !== range.value.start || end !== range.value.end) range.value = { start, end }
}

onMounted(() => {
  scroller = (root.value?.closest('[data-scroll]') as HTMLElement) ?? window
  scroller.addEventListener('scroll', measure, { passive: true })
  addEventListener('resize', measure)
  measure()
})
onBeforeUnmount(() => {
  scroller.removeEventListener('scroll', measure)
  removeEventListener('resize', measure)
})

function play(index: number) {
  player.playTracks(props.tracks, index, { context: props.context, shuffle: false })
}

function menu(track: Track, event: MouseEvent) {
  ui.openTrackMenu(track, event, props.menuContext?.(track) ?? {})
}
</script>

<template>
  <div ref="root" class="relative" :style="virtual ? { height: `${tracks.length * ROW}px` } : undefined">
    <div :style="virtual ? { transform: `translateY(${range.start * ROW}px)` } : undefined">
      <TrackRow
        v-for="item in visible"
        :key="item.track.kind + item.track.id + '-' + item.index"
        :track="item.track"
        :index="item.index"
        :show-album="showAlbum"
        :numbered="numbered"
        @play="play(item.index)"
        @menu="menu(item.track, $event)"
      />
    </div>
  </div>
</template>
