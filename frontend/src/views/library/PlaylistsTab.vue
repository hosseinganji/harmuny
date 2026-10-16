<script setup lang="ts">
import { Heart, ListMusic, Plus } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import EmptyState from '@/components/EmptyState.vue'
import NewPlaylistDialog from '@/components/NewPlaylistDialog.vue'
import PlaylistCard from '@/components/PlaylistCard.vue'
import { api } from '@/lib/api'
import { t } from '@/lib/i18n'
import type { Playlist } from '@/lib/types'
import { useLibrary } from '@/stores/library'
import { usePlaylists } from '@/stores/playlists'

const playlists = usePlaylists()
const library = useLibrary()
const creating = ref(false)
const community = ref<Playlist[]>([])

const own = computed(() => playlists.mine.filter((p) => p.is_owner))

onMounted(async () => {
  playlists.load()
  try {
    const res = await api.get<{ data: Playlist[] }>('/playlists', { include_public: 1 })
    community.value = res.data.filter((p) => !p.is_owner)
  } catch {
    // offline
  }
})
</script>

<template>
  <div class="px-2 pt-2 sm:px-4">
    <div class="grid grid-cols-2 gap-1 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
      <button class="group rounded-2xl p-2 text-start transition hover:bg-fg/5" @click="creating = true">
        <div class="flex aspect-square items-center justify-center rounded-xl border-2 border-dashed border-line transition group-hover:border-accent">
          <Plus class="size-10 text-muted group-hover:text-accent" />
        </div>
        <div class="mt-2 text-sm font-semibold">{{ t('playlist.new') }}</div>
      </button>
      <RouterLink to="/library/liked" class="rounded-2xl p-2 transition hover:bg-fg/5">
        <div class="flex aspect-square items-center justify-center rounded-xl bg-gradient-to-br from-violet-600 via-fuchsia-500 to-pink-400 shadow-lg">
          <Heart class="size-12 fill-white text-white" />
        </div>
        <div class="mt-2 text-sm font-semibold">{{ t('library.liked') }}</div>
        <div class="text-xs text-muted">{{ t('playlist.songs', { n: library.liked.size }) }}</div>
      </RouterLink>
      <PlaylistCard v-for="p in own" :key="p.id" :playlist="p" fluid />
    </div>

    <EmptyState v-if="playlists.loaded && !own.length" :icon="ListMusic" :title="t('playlist.noneYet')" :text="t('playlist.noneYetHint')" />

    <template v-if="community.length">
      <h2 class="mb-2 mt-8 px-2 text-xl font-bold">{{ t('home.community') }}</h2>
      <div class="grid grid-cols-2 gap-1 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
        <PlaylistCard v-for="p in community" :key="p.id" :playlist="p" fluid />
      </div>
    </template>
  </div>
  <NewPlaylistDialog :open="creating" @close="creating = false" />
</template>
