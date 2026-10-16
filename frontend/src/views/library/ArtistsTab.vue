<script setup lang="ts">
import { MicVocal } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import ArtistCard from '@/components/ArtistCard.vue'
import EmptyState from '@/components/EmptyState.vue'
import { t } from '@/lib/i18n'
import { useLibrary } from '@/stores/library'

const library = useLibrary()
const filter = ref('')
const artists = computed(() => (filter.value ? library.searchArtists(filter.value) : library.artists))
</script>

<template>
  <div class="px-4 pt-4 sm:px-6">
    <input v-model="filter" class="input h-10 max-w-xs rounded-full" :placeholder="t('library.filterArtists')" />
  </div>
  <EmptyState v-if="!library.artists.length" :icon="MicVocal" :title="t('library.noArtists')" />
  <div class="grid grid-cols-3 gap-1 px-2 pt-3 sm:grid-cols-4 sm:px-4 md:grid-cols-5 xl:grid-cols-7">
    <ArtistCard v-for="a in artists" :key="a.artist" :artist="a" fluid />
  </div>
</template>
