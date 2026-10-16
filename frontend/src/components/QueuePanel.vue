<script setup lang="ts">
import { GripVertical, ListX, X } from 'lucide-vue-next'
import { computed } from 'vue'
import Draggable from 'vuedraggable'
import { duration, num } from '@/lib/format'
import { t } from '@/lib/i18n'
import { type QueueItem, usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'
import PlayingBars from './PlayingBars.vue'
import TrackCover from './TrackCover.vue'

defineProps<{ compact?: boolean }>()
const player = usePlayer()
const ui = useUi()

const upcoming = computed({
  get: () => player.upcoming,
  set: (items: QueueItem[]) => player.setUpcoming(items),
})
</script>

<template>
  <div class="flex flex-col gap-1">
    <template v-if="player.current">
      <h3 class="px-2 pb-1 text-xs font-bold uppercase tracking-wider text-subtle">{{ t('queue.nowPlaying') }}</h3>
      <div class="flex items-center gap-3 rounded-xl bg-fg/5 p-2">
        <TrackCover :track="player.current.track" size="md" />
        <div class="min-w-0 flex-1">
          <div class="truncate font-medium text-accent">{{ player.current.track.title }}</div>
          <div class="truncate text-sm text-muted">{{ player.current.track.artist ?? t('track.unknownArtist') }}</div>
        </div>
        <PlayingBars :paused="!player.playing" class="me-2" />
      </div>
      <div v-if="player.context?.name" class="px-2 pt-1 text-xs text-subtle">
        {{ t('queue.playingFrom') }} <span class="font-semibold text-muted">{{ player.context.name }}</span>
      </div>
    </template>

    <div class="mt-4 flex items-center px-2 pb-1">
      <h3 class="text-xs font-bold uppercase tracking-wider text-subtle">
        {{ t('queue.nextUp') }} <span v-if="upcoming.length" class="text-muted">· {{ num(upcoming.length) }}</span>
      </h3>
      <button v-if="upcoming.length" class="ms-auto flex items-center gap-1 text-xs font-semibold text-muted hover:text-fg" @click="player.clearUpcoming()">
        <ListX class="size-3.5" />{{ t('queue.clear') }}
      </button>
    </div>

    <p v-if="!upcoming.length" class="px-2 py-6 text-center text-sm text-subtle">{{ t('queue.empty') }}</p>

    <Draggable v-model="upcoming" item-key="uid" handle=".drag" :animation="180" ghost-class="opacity-30" class="flex flex-col">
      <template #item="{ element }: { element: QueueItem }">
        <div
          class="group flex cursor-pointer items-center gap-2 rounded-xl p-1.5 hover:bg-fg/5"
          @click="player.jump(element.uid)"
          @contextmenu.prevent="ui.openTrackMenu(element.track, $event, { queueUid: element.uid })"
        >
          <span class="drag cursor-grab touch-none p-1 text-subtle hover:text-fg active:cursor-grabbing" @click.stop>
            <GripVertical class="size-4" />
          </span>
          <TrackCover :track="element.track" size="sm" />
          <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-medium">{{ element.track.title }}</div>
            <div class="truncate text-xs text-muted">{{ element.track.artist ?? t('track.unknownArtist') }}</div>
          </div>
          <span v-if="!compact" class="text-xs text-subtle tabular group-hover:hidden">{{ duration(element.track.duration) }}</span>
          <button class="icon-btn hidden size-8 group-hover:inline-flex" :aria-label="t('queue.remove')" @click.stop="player.remove(element.uid)">
            <X class="size-4" />
          </button>
        </div>
      </template>
    </Draggable>
  </div>
</template>
