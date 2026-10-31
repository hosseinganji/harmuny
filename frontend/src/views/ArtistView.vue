<script setup lang="ts">
import { MicVocal, Radio, UserCheck, UserPlus } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import CollectionActions from '@/components/CollectionActions.vue'
import CollectionHeader from '@/components/CollectionHeader.vue'
import EmptyState from '@/components/EmptyState.vue'
import SortSelect from '@/components/SortSelect.vue'
import TrackCover from '@/components/TrackCover.vue'
import TrackList from '@/components/TrackList.vue'
import { coverUrl } from '@/lib/api'
import { api } from '@/lib/api'
import { cachedGet } from '@/lib/cached'
import { usePageColor } from '@/lib/dynamicColor'
import { longDuration } from '@/lib/format'
import { t } from '@/lib/i18n'
import { startRadio } from '@/lib/radio'
import { sortTracks, useTrackSort } from '@/lib/sortTracks'
import type { ApiTrack, RemoteTrack } from '@/lib/types'
import { useLibrary } from '@/stores/library'
import { useUi } from '@/stores/ui'

const props = defineProps<{ name: string }>()
const library = useLibrary()
const fetched = ref<RemoteTrack[] | null>(null)

// The local catalog answers instantly; the server adds play-count ordering.
const all = computed(() => fetched.value ?? library.byArtist(props.name))
// An artist's songs open most-played first.
const sort = useTrackSort('artist', 'popular')
const tracks = computed(() => sortTracks(all.value, sort.value, library.playsOf))
const coverId = computed(() => tracks.value.find((x) => x.has_cover)?.id ?? null)
const total = computed(() => tracks.value.reduce((s, x) => s + x.duration, 0))
const plays = computed(() => tracks.value.reduce((s, x) => s + library.playsOf(x), 0))

usePageColor(computed(() => (coverId.value ? coverUrl(coverId.value) : null)))

// Following: their new songs come first in Release Radar, with a notification when one arrives.
const following = ref(false)
const followers = ref(0)
async function toggleFollow() {
  const was = following.value
  following.value = !was
  followers.value += was ? -1 : 1
  try {
    if (was) await api.delete('/follows', { artist: props.name })
    else await api.post('/follows', { artist: props.name })
    if (!was) useUi().toast(t('follow.followed', { name: props.name }), 'success')
  } catch {
    following.value = was
    followers.value += was ? 1 : -1
    useUi().toast(t('toast.offlineAction'), 'error')
  }
}

onMounted(async () => {
  try {
    const res = await cachedGet<{ tracks: ApiTrack[]; following?: boolean; followers?: number }>('/artist', { name: props.name })
    fetched.value = library.remember(res.tracks)
    following.value = !!res.following
    followers.value = res.followers ?? 0
  } catch {
    // offline: keep the local list
  }
})
</script>

<template>
  <EmptyState v-if="!tracks.length" :icon="MicVocal" :title="t('artist.notFound')" />
  <template v-else>
    <CollectionHeader :kicker="t('artist.kicker')" :title="name" round>
      <template #cover><TrackCover :track-id="coverId" :track="null" size="fill" rounded="rounded-full" class="aspect-square" /></template>
      <template #meta>
        <div class="mt-3 text-sm font-medium text-fg/80">
          {{ t('playlist.songs', { n: tracks.length }) }} · {{ longDuration(total) }} · {{ t('artist.plays', { n: plays }) }}<template v-if="followers"> · {{ t('follow.followers', { n: followers }) }}</template>
        </div>
      </template>
    </CollectionHeader>
    <CollectionActions :tracks="tracks" :context="{ type: 'artist', id: name, name }">
      <button class="h-10 shrink-0 whitespace-nowrap px-4" :class="following ? 'btn-ghost' : 'btn-primary'" @click="toggleFollow">
        <UserCheck v-if="following" class="size-4" /><UserPlus v-else class="size-4" />{{ following ? t('follow.following') : t('follow.follow') }}
      </button>
      <button class="btn-ghost h-10 px-4" @click="startRadio({ artist: name, name })"><Radio class="size-4" />{{ t('radio.artist') }}</button>
    </CollectionActions>
    <div class="flex items-center justify-between gap-3 px-4 pb-2 sm:px-6">
      <h2 class="text-xl font-bold">{{ t('artist.popular') }}</h2>
      <SortSelect v-model="sort" />
    </div>
    <div class="px-2 sm:px-4">
      <TrackList :tracks="tracks" numbered show-album :context="{ type: 'artist', id: name, name }" />
    </div>
  </template>
</template>
