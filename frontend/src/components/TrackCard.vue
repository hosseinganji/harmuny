<script setup lang="ts">
import { Pause, Play } from 'lucide-vue-next'
import { computed } from 'vue'
import { t } from '@/lib/i18n'
import type { Track } from '@/lib/types'
import { useLibrary } from '@/stores/library'
import { trackKey, usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'
import TrackCover from './TrackCover.vue'

const props = defineProps<{ track: Track }>()
const emit = defineEmits<{ play: [] }>()
const player = usePlayer()
const ui = useUi()
const library = useLibrary()
const isCurrent = computed(() => player.track && trackKey(player.track) === trackKey(props.track))
// Lists can hold an older copy; the catalog has the latest edit.
const shown = computed(() => (props.track.kind === 'remote' ? library.get(props.track.id) ?? props.track : props.track))
</script>

<template>
  <div
    class="group w-36 shrink-0 cursor-pointer snap-start rounded-2xl p-2 transition hover:bg-fg/5 sm:w-44"
    @click="isCurrent ? player.toggle() : emit('play')"
    @contextmenu.prevent="ui.openTrackMenu(track, $event)"
  >
    <TrackCover :track="track" size="fill" rounded="rounded-xl" class="aspect-square shadow-lg">
      <button
        class="absolute bottom-2 end-2 flex size-11 items-center justify-center rounded-full bg-accent text-accent-fg shadow-xl transition-all duration-300"
        :class="isCurrent ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-0 group-hover:translate-y-0 group-hover:opacity-100'"
        :aria-label="t('player.play')"
        @click.stop="isCurrent ? player.toggle() : emit('play')"
      >
        <Pause v-if="isCurrent && player.playing" class="size-5 fill-current" />
        <Play v-else class="size-5 translate-x-[1px] fill-current" />
      </button>
    </TrackCover>
    <div class="mt-2 truncate text-sm font-semibold" :class="{ 'text-accent': isCurrent }">{{ shown.title }}</div>
    <div class="truncate text-xs text-muted">{{ shown.artist ?? t('track.unknownArtist') }}</div>
  </div>
</template>
