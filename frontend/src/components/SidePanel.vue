<script setup lang="ts">
import { X } from 'lucide-vue-next'
import { t } from '@/lib/i18n'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'
import LyricsView from './LyricsView.vue'
import QueuePanel from './QueuePanel.vue'

const ui = useUi()
const player = usePlayer()
</script>

<template>
  <aside v-if="ui.sidePanel && player.track" class="card m-2 ms-0 hidden w-80 shrink-0 flex-col overflow-hidden lg:flex xl:w-96">
    <header class="flex items-center px-4 py-3">
      <h2 class="flex-1 font-bold">{{ ui.sidePanel === 'queue' ? t('player.queue') : t('player.lyrics') }}</h2>
      <button class="icon-btn size-8" :aria-label="t('common.close')" @click="ui.sidePanel = null"><X class="size-4.5" /></button>
    </header>
    <div class="relative min-h-0 flex-1 overflow-y-auto px-3 pb-4">
      <QueuePanel v-if="ui.sidePanel === 'queue'" />
      <div v-else class="absolute inset-0 px-5" style="background: linear-gradient(180deg, rgb(var(--dyn) / 0.25), transparent)">
        <LyricsView />
      </div>
    </div>
  </aside>
</template>
