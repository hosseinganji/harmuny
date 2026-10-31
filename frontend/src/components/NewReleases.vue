<script setup lang="ts">
import { Play, Sparkles } from 'lucide-vue-next'
import { computed } from 'vue'
import { relativeTime } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { RemoteTrack } from '@/lib/types'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import TrackCover from './TrackCover.vue'

/**
 * New releases, at the top of Home: the week's new songs as big artwork cards that snap into place, the
 * newest first. A quiet week shows the latest ten instead.
 */
const library = useLibrary()
const player = usePlayer()

const WEEK = 7 * 24 * 3600 * 1000
const releases = computed<RemoteTrack[]>(() => {
  const list = library.list
  const recent = list.filter((tr) => tr.posted_at && Date.now() - new Date(tr.posted_at).getTime() < WEEK)
  return (recent.length >= 3 ? recent : list).slice(0, 20)
})
const today = computed(() => releases.value.filter((tr) => tr.posted_at && Date.now() - new Date(tr.posted_at).getTime() < 24 * 3600 * 1000).length)
const context = computed(() => ({ type: 'home' as const, name: t('releases.title') }))
const isFresh = (tr: RemoteTrack) => !!tr.posted_at && Date.now() - new Date(tr.posted_at).getTime() < 2 * 24 * 3600 * 1000

function play(i: number) {
  player.playTracks(releases.value, i, { shuffle: false, context: context.value })
}
</script>

<template>
  <section v-if="releases.length" class="mt-6">
    <div class="mb-3 flex items-end gap-3 px-4 sm:px-6">
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-[0.2em] text-accent">
          <Sparkles class="size-3.5 fill-current" />{{ today ? t('releases.today', { n: today }) : t('releases.thisWeek') }}
        </div>
        <h2 class="text-2xl font-black tracking-tight">{{ t('releases.title') }}</h2>
      </div>
      <button class="chip h-9 shrink-0 text-sm" @click="play(0)"><Play class="size-3.5 fill-current" />{{ t('releases.playAll') }}</button>
    </div>

    <div class="flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-px-4 px-4 pb-2 scrollbar-none sm:scroll-px-6 sm:px-6">
      <button
        v-for="(tr, i) in releases"
        :key="tr.id"
        class="group relative aspect-[4/5] w-[72%] max-w-[17rem] shrink-0 snap-start overflow-hidden rounded-[1.75rem] text-start shadow-[0_18px_40px_-18px_rgba(0,0,0,0.8)] transition active:scale-[0.98] sm:w-56"
        @click="play(i)"
      >
        <TrackCover :track="tr" size="fill" rounded="rounded-none" class="absolute inset-0 !size-full transition-transform duration-500 group-hover:scale-105" />
        <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/25 to-transparent" />
        <div class="absolute inset-x-0 top-0 flex items-center gap-1.5 p-3">
          <span v-if="isFresh(tr)" class="rounded-full bg-accent px-2.5 py-1 text-[10px] font-black tracking-widest text-white shadow-lg">NEW</span>
          <span class="rounded-full bg-black/45 px-2 py-1 text-[10px] font-semibold text-white/90 backdrop-blur-sm">{{ relativeTime(tr.posted_at) }}</span>
        </div>
        <div class="absolute inset-x-0 bottom-0 flex items-end gap-2 p-4 text-white">
          <div class="min-w-0 flex-1">
            <div class="line-clamp-2 text-xl font-black leading-tight">{{ tr.title }}</div>
            <div class="mt-0.5 truncate text-sm text-white/75">{{ tr.artist ?? t('track.unknownArtist') }}</div>
          </div>
          <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-white text-black shadow-xl transition group-active:scale-90">
            <Play class="size-5 translate-x-px fill-current" />
          </span>
        </div>
      </button>
    </div>
  </section>
</template>
