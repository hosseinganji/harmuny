<script setup lang="ts">
import { Loader2, Pause, Play, Repeat, Repeat1, Shuffle, SkipBack, SkipForward } from 'lucide-vue-next'
import { t } from '@/lib/i18n'
import { usePlayer } from '@/stores/player'

withDefaults(defineProps<{ large?: boolean }>(), { large: false })
const player = usePlayer()
</script>

<template>
  <!-- Buttons never shrink (a squeezed play button turns oval); in the big player they spread evenly across the row,
       so narrow phones get as much space between them as the row allows. -->
  <div
    dir="ltr"
    class="flex items-center [&>button]:aspect-square [&>button]:shrink-0"
    :class="large ? 'mx-auto w-full max-w-[22rem] justify-between' : 'justify-center gap-3'"
  >
    <button
      class="icon-btn relative"
      :class="[large ? 'size-11 min-[400px]:size-12' : 'size-9', player.shuffle ? '!text-accent' : '']"
      :aria-label="t('player.shuffle')"
      :aria-pressed="player.shuffle"
      @click="player.toggleShuffle()"
    >
      <Shuffle :class="large ? 'size-6' : 'size-4.5'" />
      <span v-if="player.shuffle" class="absolute bottom-0.5 size-1 rounded-full bg-accent" />
    </button>
    <button class="icon-btn text-fg" :class="large ? 'size-12 min-[400px]:size-14' : 'size-9'" :aria-label="t('player.previous')" @click="player.prev()">
      <SkipBack class="fill-current" :class="large ? 'size-8' : 'size-5'" />
    </button>
    <button
      class="flex items-center justify-center rounded-full bg-fg text-bg shadow-xl transition hover:scale-105 active:scale-95"
      :class="large ? 'size-17 min-[400px]:size-18' : 'size-10'"
      :aria-label="player.playing ? t('player.pause') : t('player.play')"
      @click="player.toggle()"
    >
      <Transition name="icon" mode="out-in">
        <Loader2 v-if="player.loading && player.playing" class="animate-spin" :class="large ? 'size-8' : 'size-5'" />
        <Pause v-else-if="player.playing" class="fill-current" :class="large ? 'size-8' : 'size-5'" />
        <Play v-else class="translate-x-[1px] fill-current" :class="large ? 'size-8' : 'size-5'" />
      </Transition>
    </button>
    <button class="icon-btn text-fg" :class="large ? 'size-12 min-[400px]:size-14' : 'size-9'" :aria-label="t('player.next')" @click="player.next()">
      <SkipForward class="fill-current" :class="large ? 'size-8' : 'size-5'" />
    </button>
    <button
      class="icon-btn relative"
      :class="[large ? 'size-11 min-[400px]:size-12' : 'size-9', player.repeat !== 'off' ? '!text-accent' : '']"
      :aria-label="t('player.repeat.' + player.repeat)"
      @click="player.cycleRepeat()"
    >
      <Repeat1 v-if="player.repeat === 'one'" :class="large ? 'size-6' : 'size-4.5'" />
      <Repeat v-else :class="large ? 'size-6' : 'size-4.5'" />
      <span v-if="player.repeat !== 'off'" class="absolute bottom-0.5 size-1 rounded-full bg-accent" />
    </button>
  </div>
</template>
