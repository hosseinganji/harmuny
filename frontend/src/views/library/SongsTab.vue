<script setup lang="ts">
import { Music, RefreshCw } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import CollectionActions from '@/components/CollectionActions.vue'
import EmptyState from '@/components/EmptyState.vue'
import TrackList from '@/components/TrackList.vue'
import { t } from '@/lib/i18n'
import { useLibrary } from '@/stores/library'

const library = useLibrary()
const filter = ref('')
const sort = ref<'recent' | 'title' | 'artist' | 'popular' | 'duration'>((localStorage.getItem('smp.songSort') as any) ?? 'recent')

const tracks = computed(() => {
  const base = filter.value ? library.search(filter.value, 100000) : library.list
  const list = [...base]
  localStorage.setItem('smp.songSort', sort.value)
  switch (sort.value) {
    case 'title': return list.sort((a, b) => a.title.localeCompare(b.title))
    case 'artist': return list.sort((a, b) => (a.artist ?? '').localeCompare(b.artist ?? '') || a.title.localeCompare(b.title))
    case 'popular': return list.sort((a, b) => b.play_count - a.play_count)
    case 'duration': return list.sort((a, b) => b.duration - a.duration)
    default: return list
  }
})
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
    <select v-model="sort" class="input h-10 w-auto rounded-full pe-8">
      <option value="recent">{{ t('sort.recent') }}</option>
      <option value="title">{{ t('sort.title') }}</option>
      <option value="artist">{{ t('sort.artist') }}</option>
      <option value="popular">{{ t('sort.popular') }}</option>
      <option value="duration">{{ t('sort.duration') }}</option>
    </select>
  </div>
  <EmptyState v-if="!library.tracks.size" :icon="Music" :title="t('library.emptyTitle')" :text="t('library.emptyText')" />
  <div v-else class="px-2 sm:px-4">
    <TrackList :tracks="tracks" show-album :context="{ type: 'library', name: t('library.allSongs') }" />
  </div>
</template>
