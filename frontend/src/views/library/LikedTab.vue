<script setup lang="ts">
import { Heart } from 'lucide-vue-next'
import CollectionActions from '@/components/CollectionActions.vue'
import EmptyState from '@/components/EmptyState.vue'
import TrackList from '@/components/TrackList.vue'
import { longDuration } from '@/lib/format'
import { t } from '@/lib/i18n'
import { useLibrary } from '@/stores/library'
import { useSettings } from '@/stores/settings'

const library = useLibrary()
const settings = useSettings()
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
  <CollectionActions :tracks="library.likedTracks" :context="{ type: 'liked', name: t('library.liked') }">
    <label class="flex cursor-pointer items-center gap-2 text-sm text-muted">
      <span class="hidden sm:inline">{{ t('settings.autoDownloadLiked') }}</span>
      <span class="sm:hidden">{{ t('settings.autoDownloadShort') }}</span>
      <input
        type="checkbox"
        class="size-4 accent-[var(--accent)]"
        :checked="settings.prefs.autoDownloadLiked"
        @change="settings.update({ autoDownloadLiked: ($event.target as HTMLInputElement).checked })"
      />
    </label>
  </CollectionActions>
  <EmptyState v-if="!library.likedTracks.length" :icon="Heart" :title="t('library.noLiked')" :text="t('library.noLikedHint')" />
  <div class="px-2 sm:px-4">
    <TrackList :tracks="library.likedTracks" show-album :context="{ type: 'liked', name: t('library.liked') }" />
  </div>
</template>
