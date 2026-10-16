<script setup lang="ts">
import { t } from '@/lib/i18n'
import type { ArtistSummary } from '@/lib/types'
import TrackCover from './TrackCover.vue'

defineProps<{ artist: ArtistSummary; fluid?: boolean }>()
</script>

<template>
  <RouterLink
    :to="{ name: 'artist', query: { name: artist.artist } }"
    class="group shrink-0 snap-start rounded-2xl p-2 text-center transition hover:bg-fg/5"
    :class="fluid ? 'w-full' : 'w-32 sm:w-40'"
  >
    <TrackCover :track-id="artist.cover_track_id" :track="null" size="fill" rounded="rounded-full" class="aspect-square shadow-lg transition group-hover:scale-[1.03]" />
    <div class="mt-2 truncate text-sm font-semibold">{{ artist.artist }}</div>
    <div class="text-xs text-muted">{{ t('artist.songs', { n: artist.tracks_count }) }}</div>
  </RouterLink>
</template>
