<script setup lang="ts">
import { Heart } from 'lucide-vue-next'
import { computed } from 'vue'
import CollectionActions from '@/components/CollectionActions.vue'
import AutoDownloadToggle from '@/components/AutoDownloadToggle.vue'
import EmptyState from '@/components/EmptyState.vue'
import SortSelect from '@/components/SortSelect.vue'
import TrackList from '@/components/TrackList.vue'
import { longDuration } from '@/lib/format'
import { t } from '@/lib/i18n'
import { sortTracks, useTrackSort } from '@/lib/sortTracks'
import { useLibrary } from '@/stores/library'
import { useSettings } from '@/stores/settings'

const library = useLibrary()
const settings = useSettings()
const sort = useTrackSort('liked', 'natural')
const tracks = computed(() => sortTracks(library.likedTracks, sort.value, library.playsOf))
</script>

<template>
  <header class="flex items-end gap-5 px-4 pt-6 sm:px-6">
    <div class="flex size-28 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-600 via-fuchsia-500 to-pink-400 shadow-2xl sm:size-40">
      <Heart class="size-12 fill-white text-white sm:size-16" />
    </div>
    <div class="min-w-0">
      <h2 class="text-3xl font-black sm:text-5xl">{{ t('library.liked') }}</h2>
      <p class="mt-2 text-sm text-muted">
        {{ t('playlist.songs', { n: library.likedTracks.length }) }} · {{ longDuration(library.likedTracks.reduce((s, x) => s + x.duration, 0)) }}
      </p>
    </div>
  </header>
  <CollectionActions :tracks="tracks" :context="{ type: 'liked', name: t('library.liked') }" />
  <EmptyState v-if="!library.likedTracks.length" :icon="Heart" :title="t('library.noLiked')" :text="t('library.noLikedHint')" />
  <div class="flex flex-wrap items-center gap-2 px-4 pb-2 sm:px-6">
    <SortSelect v-if="tracks.length > 1" v-model="sort" :natural-label="t('sort.dateLiked')" />
    <AutoDownloadToggle
      :model-value="!!settings.prefs.autoDownloadLiked"
      :hint="t('settings.autoDownloadHint')"
      @update:model-value="settings.update({ autoDownloadLiked: $event })"
    />
  </div>
  <div class="px-2 sm:px-4">
    <TrackList :tracks="tracks" show-album :context="{ type: 'liked', name: t('library.liked') }" />
  </div>
</template>
