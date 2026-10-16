<script setup lang="ts">
import { t } from '@/lib/i18n'
import type { Playlist } from '@/lib/types'
import CoverMosaic from './CoverMosaic.vue'

defineProps<{ playlist: Playlist; fluid?: boolean }>()
</script>

<template>
  <RouterLink
    :to="{ name: 'playlist', params: { id: playlist.id } }"
    class="group shrink-0 snap-start rounded-2xl p-2 transition hover:bg-fg/5"
    :class="fluid ? 'w-full' : 'w-36 sm:w-44'"
  >
    <CoverMosaic :cover-url="playlist.cover_url" :track-ids="playlist.preview_track_ids" class="shadow-lg" />
    <div class="mt-2 truncate text-sm font-semibold">{{ playlist.name }}</div>
    <div class="truncate text-xs text-muted">
      {{ playlist.is_owner ? t('playlist.songs', { n: playlist.tracks_count ?? 0 }) : t('playlist.by', { name: playlist.owner.name ?? '' }) }}
    </div>
  </RouterLink>
</template>
