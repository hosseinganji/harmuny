<script setup lang="ts">
import { ChevronRight, Download, HardDrive, Heart, Play, Radar, RefreshCw, Sparkles } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import ArtistCard from '@/components/ArtistCard.vue'
import NewReleases from '@/components/NewReleases.vue'
import PlaylistCard from '@/components/PlaylistCard.vue'
import Shelf from '@/components/Shelf.vue'
import TrackCard from '@/components/TrackCard.vue'
import TrackCover from '@/components/TrackCover.vue'
import { cachedGet } from '@/lib/cached'
import { count } from '@/lib/format'
import { t } from '@/lib/i18n'
import { playDailyMix } from '@/lib/radio'
import type { ApiTrack, Playlist, RemoteTrack } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useDownloads } from '@/stores/downloads'
import { useLibrary } from '@/stores/library'
import { type PlayContext, usePlayer } from '@/stores/player'
import { usePlaylists } from '@/stores/playlists'

interface HomeData {
  recently_added: ApiTrack[]
  recently_played: ApiTrack[]
  your_top: ApiTrack[]
  popular: ApiTrack[]
  discover: ApiTrack[]
  community_playlists: Playlist[]
  total_tracks: number
}

const auth = useAuth()
const library = useLibrary()
const player = usePlayer()
const playlists = usePlaylists()
const downloads = useDownloads()
const home = ref<HomeData | null>(null)

const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 5 ? t('home.night') : h < 12 ? t('home.morning') : h < 17 ? t('home.afternoon') : h < 21 ? t('home.evening') : t('home.night')
})

const shelves = computed(() => {
  const h = home.value
  if (!h) return []
  const s: { key: string; title: string; tracks: RemoteTrack[]; context: PlayContext }[] = [
    { key: 'recent', title: t('home.jumpBackIn'), tracks: library.remember(h.recently_played), context: { type: 'home', name: t('home.jumpBackIn') } },
    // Everyone's most played songs, right after the user's own recent ones.
    { key: 'popular', title: t('home.mostPlayed'), tracks: library.remember(h.popular), context: { type: 'home', name: t('home.mostPlayed') } },
    { key: 'new', title: t('home.recentlyAdded'), tracks: library.remember(h.recently_added), context: { type: 'home', name: t('home.recentlyAdded') } },
    { key: 'top', title: t('home.yourTop'), tracks: library.remember(h.your_top), context: { type: 'home', name: t('home.yourTop') } },
    { key: 'discover', title: t('home.discover'), tracks: library.remember(h.discover), context: { type: 'home', name: t('home.discover') } },
  ]
  return s.filter((x) => x.tracks.length)
})

const topArtists = computed(() => library.artists.slice(0, 15))
const quick = computed(() => [
  { to: '/library/device', icon: HardDrive, label: t('library.device'), n: null, color: 'from-orange-600 to-amber-500' },
  { to: '/library/downloads', icon: Download, label: t('library.downloads'), n: downloads.count, color: 'from-emerald-600 to-teal-500' },
])

// Today's mix, made from this member's taste (kept for offline use like the rest of Home).
const mix = ref<ApiTrack[]>([])
const mixCovers = computed(() => library.remember(mix.value.filter((x) => x.has_cover).slice(0, 4)))

// Release Radar: what is new from the artists this member follows, likes or plays.
const radar = ref<{ data: ApiTrack[]; personal: number } | null>(null)
const radarCovers = computed(() => library.remember((radar.value?.data ?? []).filter((x) => x.has_cover).slice(0, 4)))
function playRadar() {
  const list = radar.value?.data ?? []
  if (list.length) player.playTracks(library.remember(list), 0, { shuffle: false, context: { type: 'mix', name: t('radar.title') } })
}

async function load() {
  cachedGet<{ data: ApiTrack[]; personal: number }>('/mixes/release-radar', undefined, (c) => (radar.value = c))
    .then((res) => (radar.value = res))
    .catch(() => {})
  cachedGet<{ data: ApiTrack[] }>('/mixes/daily', undefined, (c) => (mix.value = c.data))
    .then((res) => (mix.value = res.data))
    .catch(() => {})
  try {
    home.value = await cachedGet<HomeData>('/home', undefined, (c) => (home.value ??= c))
  } catch {
    // offline without cache
  }
}

onMounted(load)
</script>

<template>
  <div class="dyn-gradient pb-6">
    <div class="flex items-center gap-3 px-4 pt-6 sm:px-6 sm:pt-10">
      <div class="min-w-0 flex-1">
        <h1 class="text-3xl font-black tracking-tight sm:text-4xl">{{ greeting }}<span class="text-muted">{{ t('home.greetingSep') }}{{ auth.user?.name?.split(' ')[0] }}</span></h1>
        <p class="mt-1 text-sm text-muted">
          {{ t('home.librarySize', { n: library.tracks.size }) }}
          <RefreshCw v-if="library.syncing" class="ms-1 inline size-3.5 animate-spin" />
        </p>
      </div>
    </div>

    <NewReleases />

    <div class="mt-6 grid grid-cols-2 gap-2 px-4 sm:gap-3 sm:px-6">
      <!-- Liked songs gets the full width; the two below share a row. -->
      <RouterLink
        to="/library/liked"
        class="col-span-2 flex h-[4.5rem] items-center gap-3.5 overflow-hidden rounded-2xl bg-gradient-to-br from-accent to-[color-mix(in_oklab,var(--accent)_55%,#ff4fa3)] px-5 text-white shadow-lg transition active:scale-[0.98]"
      >
        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white/20"><Heart class="size-5 fill-current" /></span>
        <span class="min-w-0 flex-1">
          <span class="block text-base font-black leading-tight">{{ t('library.liked') }}</span>
          <span class="block text-sm text-white/75">{{ t('home.likedCount', { n: count(library.liked.size) }) }}</span>
        </span>
        <ChevronRight class="size-6 text-white/70 rtl:rotate-180" />
      </RouterLink>
      <RouterLink
        v-for="q in quick"
        :key="q.to"
        :to="q.to"
        class="group flex items-center gap-3 overflow-hidden rounded-xl bg-fg/[0.06] transition hover:bg-fg/10"
      >
        <span class="flex size-14 shrink-0 items-center justify-center bg-gradient-to-br text-white" :class="q.color">
          <component :is="q.icon" class="size-6" />
        </span>
        <span class="min-w-0 truncate text-sm font-bold">{{ q.label }}</span>
        <span v-if="q.n" class="ms-auto me-3 text-xs text-subtle">{{ count(q.n) }}</span>
      </RouterLink>

      <!-- Daily mix: made from this member's taste, new every day. -->
      <button
        v-if="mix.length"
        class="group col-span-2 flex items-center gap-4 overflow-hidden rounded-2xl bg-fg/[0.06] p-3 text-start transition hover:bg-fg/10 active:scale-[0.98]"
        @click="playDailyMix(mix)"
      >
        <span class="grid size-16 shrink-0 grid-cols-2 overflow-hidden rounded-xl shadow-lg">
          <TrackCover v-for="tr in mixCovers" :key="tr.id" :track="tr" size="fill" rounded="rounded-none" />
        </span>
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-1.5 text-xs font-semibold text-accent"><Sparkles class="size-3.5" />{{ t('mix.kicker') }}</span>
          <span class="block truncate text-lg font-black">{{ t('mix.daily') }}</span>
          <span class="block truncate text-xs text-muted">{{ t('mix.dailyHint', { n: mix.length }) }}</span>
        </span>
        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white shadow-lg transition group-active:scale-90"><Play class="size-5 translate-x-px fill-current" /></span>
      </button>

      <!-- Release Radar: new songs by the artists this member follows. -->
      <button
        v-if="radar?.data.length"
        class="group col-span-2 flex items-center gap-4 overflow-hidden rounded-2xl bg-fg/[0.06] p-3 text-start transition hover:bg-fg/10 active:scale-[0.98]"
        @click="playRadar"
      >
        <span class="grid size-16 shrink-0 grid-cols-2 overflow-hidden rounded-xl shadow-lg">
          <TrackCover v-for="tr in radarCovers" :key="tr.id" :track="tr" size="fill" rounded="rounded-none" />
        </span>
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-1.5 text-xs font-semibold text-accent"><Radar class="size-3.5" />{{ t('radar.kicker') }}</span>
          <span class="block truncate text-lg font-black">{{ t('radar.title') }}</span>
          <span class="block truncate text-xs text-muted">{{ radar.personal ? t('radar.personal', { n: radar.personal }) : t('radar.general', { n: radar.data.length }) }}</span>
        </span>
        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white shadow-lg transition group-active:scale-90"><Play class="size-5 translate-x-px fill-current" /></span>
      </button>
    </div>
  </div>

  <div class="space-y-8">
    <template v-if="!home">
      <div v-for="i in 3" :key="i" class="px-4 sm:px-6">
        <div class="skeleton mb-3 h-7 w-48 rounded-lg" />
        <div class="flex gap-4 overflow-hidden">
          <div v-for="j in 6" :key="j" class="skeleton aspect-square w-36 shrink-0 rounded-xl sm:w-44" />
        </div>
      </div>
    </template>

    <Shelf v-for="shelf in shelves" :key="shelf.key" :title="shelf.title">
      <TrackCard
        v-for="(track, i) in shelf.tracks"
        :key="track.id"
        :track="track"
        @play="player.playTracks(shelf.tracks, i, { context: shelf.context, shuffle: false })"
      />
    </Shelf>

    <Shelf v-if="playlists.mine.length" :title="t('library.playlists')" to="/library">
      <PlaylistCard v-for="p in playlists.mine.slice(0, 12)" :key="p.id" :playlist="p" />
    </Shelf>

    <Shelf v-if="topArtists.length" :title="t('home.artists')" to="/library/artists">
      <ArtistCard v-for="a in topArtists" :key="a.artist" :artist="a" />
    </Shelf>

    <Shelf v-if="home?.community_playlists.length" :title="t('home.community')">
      <PlaylistCard v-for="p in home.community_playlists" :key="p.id" :playlist="p" />
    </Shelf>
  </div>
</template>
