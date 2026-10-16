<script setup lang="ts">
import { Volume1, Volume2, VolumeX } from 'lucide-vue-next'
import { t } from '@/lib/i18n'
import { usePlayer } from '@/stores/player'
import SeekBar from './SeekBar.vue'

const player = usePlayer()

function wheel(e: WheelEvent) {
  player.setVolume(player.volume - Math.sign(e.deltaY) * 0.05)
}
</script>

<template>
  <div class="flex items-center gap-1" @wheel.prevent="wheel">
    <button class="icon-btn size-9" :aria-label="t('player.mute')" @click="player.toggleMute()">
      <VolumeX v-if="player.muted || player.volume === 0" class="size-5" />
      <Volume1 v-else-if="player.volume < 0.5" class="size-5" />
      <Volume2 v-else class="size-5" />
    </button>
    <SeekBar class="w-24" :value="player.muted ? 0 : player.volume" :label="t('player.volume')" @seek="player.setVolume" @preview="(v) => v !== null && player.setVolume(v)" />
  </div>
</template>
