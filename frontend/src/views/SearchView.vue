<script setup lang="ts">
import { useDebounceFn, useOnline } from '@vueuse/core'
import { Clock, Search, SearchX, X } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ArtistCard from '@/components/ArtistCard.vue'
import EmptyState from '@/components/EmptyState.vue'
import PlaylistCard from '@/components/PlaylistCard.vue'
import TrackCover from '@/components/TrackCover.vue'
import TrackList from '@/components/TrackList.vue'
import { api } from '@/lib/api'
import { t } from '@/lib/i18n'
import { matches, normalize, words } from '@/lib/normalize'
import type { Playlist, Track } from '@/lib/types'
import { useLibrary } from '@/stores/library'
import { useLocal } from '@/stores/local'
import { usePlayer } from '@/stores/player'

const RECENT_KEY = 'smp.recentSearches'

const library = useLibrary()
const local = useLocal()
const player = usePlayer()
const route = useRoute()
const router = useRouter()
const online = useOnline()

const query = ref(String(route.query.q ?? ''))
const input = ref<HTMLInputElement>()
const playlists = ref<Playlist[]>([])
const recent = ref<string[]>(JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]'))
const tab = ref<'all' | 'songs' | 'artists' | 'playlists' | 'device'>('all')

const remoteTracks = computed(() => library.search(query.value))
const localTracks = computed<Track[]>(() => {
  const q = words(query.value)
  if (!q.length) return []
  return local.tracks.filter((tr) => matches(normalize([tr.title, tr.artist, tr.album, tr.file_name].join(' ')), q)).slice(0, 100)
})
const artists = computed(() => library.searchArtists(query.value))
const top = computed<Track | null>(() => remoteTracks.value[0] ?? localTracks.value[0] ?? null)
const hasResults = computed(() => remoteTracks.value.length || localTracks.value.length || artists.value.length || playlists.value.length)

const fetchPlaylists = useDebounceFn(async (q: string) => {
  if (!online.value || !words(q).length) {
    playlists.value = []
    return
  }
  try {
    const res = await api.get<{ playlists: Playlist[] }>('/search', { q })
    if (q === query.value) playlists.value = res.playlists
  } catch {
    playlists.value = []
  }
}, 300)

const syncUrl = useDebounceFn((q: string) => router.replace({ query: q ? { q } : {} }), 400)

watch(query, (q) => {
  fetchPlaylists(q)
  syncUrl(q)
}, { immediate: true })

function remember() {
  const q = query.value.trim()
  if (!q) return
  recent.value = [q, ...recent.value.filter((r) => r !== q)].slice(0, 10)
  localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value))
}

function forget(q: string) {
  recent.value = recent.value.filter((r) => r !== q)
  localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value))
}

function playTop() {
  if (!top.value) return
  remember()
  const list = top.value.kind === 'remote' ? remoteTracks.value : localTracks.value
  player.playTracks(list, 0, { context: { type: 'search', name: query.value }, shuffle: false })
}

onMounted(() => {
  local.load()
  if (!query.value) input.value?.focus()
})

const tabs = computed(() => [
  { key: 'all', label: t('search.all') },
  { key: 'songs', label: t('search.songs'), n: remoteTracks.value.length },
  { key: 'artists', label: t('search.artists'), n: artists.value.length },
  { key: 'playlists', label: t('search.playlists'), n: playlists.value.length },
  { key: 'device', label: t('library.device'), n: localTracks.value.length },
] as const)
</script>

<template>
  <div class="glass sticky top-0 z-20 px-4 pb-3 pt-4 sm:px-6">
    <div class="relative">
      <Search class="pointer-events-none absolute start-4 top-1/2 size-5 -translate-y-1/2 text-muted" />
      <input
        ref="input"
        v-model="query"
        type="search"
        class="input h-13 rounded-full ps-12 pe-12 text-base"
        :placeholder="t('search.placeholder')"
        enterkeyhint="search"
        @keydown.enter="remember(); input?.blur()"
      />
      <button v-if="query" class="icon-btn absolute end-1.5 top-1/2 -translate-y-1/2" :aria-label="t('common.clear')" @click="query = ''; input?.focus()">
        <X class="size-5" />
      </button>
    </div>
    <div v-if="query && hasResults" class="mt-3 flex gap-2 overflow-x-auto scrollbar-none">
      <button
        v-for="tb in tabs.filter((x) => x.key === 'all' || ('n' in x && x.n))"
        :key="tb.key"
        class="chip"
        :class="{ 'chip-active': tab === tb.key }"
        @click="tab = tb.key"
      >
        {{ tb.label }}
      </button>
    </div>
  </div>

  <!-- Empty query: recent searches and artists to browse -->
  <div v-if="!query" class="px-4 sm:px-6">
    <section v-if="recent.length" class="mt-4">
      <h2 class="mb-2 text-lg font-bold">{{ t('search.recent') }}</h2>
      <div class="flex flex-wrap gap-2">
        <div v-for="r in recent" :key="r" class="chip pe-1.5">
          <Clock class="size-3.5 text-subtle" />
          <button @click="query = r">{{ r }}</button>
          <button class="rounded-full p-1 hover:bg-fg/10" :aria-label="t('common.remove')" @click="forget(r)"><X class="size-3.5" /></button>
        </div>
      </div>
    </section>
    <section class="mt-8">
      <h2 class="mb-3 text-lg font-bold">{{ t('search.browseArtists') }}</h2>
      <div class="grid grid-cols-3 gap-1 sm:grid-cols-4 md:grid-cols-5 xl:grid-cols-7">
        <ArtistCard v-for="a in library.artists.slice(0, 28)" :key="a.artist" :artist="a" fluid />
      </div>
    </section>
  </div>

  <EmptyState v-else-if="!hasResults" :icon="SearchX" :title="t('search.noResults', { q: query })" :text="t('search.noResultsHint')">
    <!-- Not in the library: ask for it. -->
    <RouterLink :to="{ name: 'requests', query: { q: query } }" class="btn-primary">{{ t('requests.askFor') }}</RouterLink>
  </EmptyState>

  <div v-else class="space-y-8 px-2 pt-2 sm:px-4">
    <template v-if="tab === 'all'">
      <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <section v-if="top" class="px-2">
          <h2 class="mb-3 text-xl font-bold">{{ t('search.topResult') }}</h2>
          <button class="group relative w-full rounded-2xl bg-fg/5 p-5 text-start transition hover:bg-fg/10" @click="playTop">
            <TrackCover :track="top" size="lg" class="!size-24 shadow-xl" />
            <div class="mt-4 truncate text-2xl font-black">{{ top.title }}</div>
            <div class="mt-1 text-sm text-muted">
              <span class="rounded-full bg-bg/60 px-2 py-0.5 text-xs font-bold uppercase">{{ t('search.song') }}</span>
              {{ top.artist }}
            </div>
          </button>
        </section>
        <section v-if="remoteTracks.length || localTracks.length">
          <h2 class="mb-1 px-2 text-xl font-bold">{{ t('search.songs') }}</h2>
          <TrackList :tracks="(remoteTracks.length ? remoteTracks : localTracks).slice(0, 5)" :context="{ type: 'search', name: query }" />
        </section>
      </div>
      <section v-if="artists.length">
        <h2 class="mb-2 px-2 text-xl font-bold">{{ t('search.artists') }}</h2>
        <div class="flex gap-1 overflow-x-auto scrollbar-none">
          <ArtistCard v-for="a in artists" :key="a.artist" :artist="a" />
        </div>
      </section>
      <section v-if="playlists.length">
        <h2 class="mb-2 px-2 text-xl font-bold">{{ t('search.playlists') }}</h2>
        <div class="flex gap-1 overflow-x-auto scrollbar-none">
          <PlaylistCard v-for="p in playlists" :key="p.id" :playlist="p" />
        </div>
      </section>
      <section v-if="localTracks.length && remoteTracks.length">
        <h2 class="mb-1 px-2 text-xl font-bold">{{ t('library.device') }}</h2>
        <TrackList :tracks="localTracks.slice(0, 5)" :context="{ type: 'local', name: t('library.device') }" />
      </section>
    </template>

    <TrackList v-else-if="tab === 'songs'" :tracks="remoteTracks" show-album :context="{ type: 'search', name: query }" />
    <div v-else-if="tab === 'artists'" class="grid grid-cols-3 gap-1 sm:grid-cols-4 lg:grid-cols-6">
      <ArtistCard v-for="a in artists" :key="a.artist" :artist="a" fluid />
    </div>
    <div v-else-if="tab === 'playlists'" class="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:grid-cols-5">
      <PlaylistCard v-for="p in playlists" :key="p.id" :playlist="p" fluid />
    </div>
    <TrackList v-else :tracks="localTracks" :context="{ type: 'local', name: t('library.device') }" />
  </div>
</template>
