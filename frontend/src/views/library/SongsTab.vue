<script setup lang="ts">
import { Music, RefreshCw } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import CollectionActions from '@/components/CollectionActions.vue'
import EmptyState from '@/components/EmptyState.vue'
import SortSelect from '@/components/SortSelect.vue'
import TrackList from '@/components/TrackList.vue'
import { t } from '@/lib/i18n'
import { sortTracks, useTrackSort } from '@/lib/sortTracks'
import { useLibrary } from '@/stores/library'

const library = useLibrary()
const filter = ref('')
const sort = useTrackSort('songs', 'recent')

const tracks = computed(() => sortTracks(filter.value ? library.search(filter.value, 100000) : library.list, sort.value, library.playsOf))
</script>

<template>
  <CollectionActions :tracks="tracks" :context="{ type: 'library', name: t('library.allSongs') }" :downloadable="false">
    <span class="hidden text-sm text-muted sm:inline">{{ t('playlist.songs', { n: tracks.length }) }}</span>
    <button class="icon-btn" :aria-label="t('library.sync')" @click="library.sync()">
      <RefreshCw class="size-5" :class="{ 'animate-spin': library.syncing }" />
    </button>
  </CollectionActions>
  <div class="flex flex-wrap gap-2 px-4 pb-2 sm:px-6">
    <input v-model="filter" class="input h-10 max-w-xs flex-1 rounded-full" :placeholder="t('library.filter')" />
    <SortSelect v-model="sort" />
  </div>
  <EmptyState v-if="!library.tracks.size" :icon="Music" :title="t('library.emptyTitle')" :text="t('library.emptyText')" />
  <div v-else class="px-2 sm:px-4">
    <TrackList :tracks="tracks" show-album :context="{ type: 'library', name: t('library.allSongs') }" />
  </div>
</template>
