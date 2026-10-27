<script setup lang="ts">
import { useDocumentVisibility, useIntervalFn, useOnline } from '@vueuse/core'
import { ref, watch } from 'vue'
import Avatar from '@/components/Avatar.vue'
import Modal from '@/components/Modal.vue'
import { Play } from 'lucide-vue-next'
import PlayingBars from '@/components/PlayingBars.vue'
import TrackCover from '@/components/TrackCover.vue'
import { api } from '@/lib/api'
import { t } from '@/lib/i18n'
import type { ApiTrack } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'

/** safe: pad for the status bar (false when the offline banner above already does). */
defineProps<{ safe: boolean }>()
const auth = useAuth()
const ui = useUi()
const library = useLibrary()
const player = usePlayer()

/** Play what a friend is listening to. */
function listenAlong(track: ApiTrack) {
  const [mine] = library.remember([track])
  player.playTracks([mine], 0, { shuffle: false })
  listOpen.value = false
}

interface OnlineUser { id: number; name: string; username: string; avatar_url: string | null; is_me: boolean; now_playing: ApiTrack | null }
const online = ref<OnlineUser[]>([])
const listOpen = ref(false)
const visible = useDocumentVisibility()
const connected = useOnline()

// Asking who is online also tells the server we are here, so poll only while the app is in view.
async function refresh() {
  if (visible.value !== 'visible' || !connected.value) return
  try {
    online.value = (await api.get<{ data: OnlineUser[] }>('/online')).data
  } catch {
    // Keep the last list; the next tick retries.
  }
}
useIntervalFn(refresh, 45_000, { immediateCallback: true })
watch(visible, (v) => v === 'visible' && refresh())
</script>

<template>
  <!-- Phones: profile (opens the drawer) at the start, the wordmark centred, who is online at the end. -->
  <div class="grid shrink-0 grid-cols-[1fr_auto_1fr] items-center gap-2 px-4 pb-3 lg:hidden" :class="safe ? 'pt-[calc(var(--safe-top)+0.5rem)]' : 'pt-2'">
    <button class="liquid justify-self-start rounded-full p-0.5 transition active:scale-90" :aria-label="t('nav.profile')" @click="ui.drawerOpen = true">
      <Avatar :name="auth.user?.name" :src="auth.user?.avatar_url" size="size-9 text-sm" />
    </button>

    <RouterLink to="/" class="font-brand pb-1 text-[1.7rem] leading-tight text-fg">{{ t('app.name') }}</RouterLink>

    <button
      v-if="online.length"
      class="liquid flex h-10 items-center gap-1.5 justify-self-end rounded-full pe-2.5 ps-1.5 text-xs font-semibold transition active:scale-95"
      :aria-label="t('online.title')"
      @click="listOpen = true"
    >
      <span class="flex -space-x-2 rtl:space-x-reverse">
        <Avatar v-for="u in online.slice(0, 2)" :key="u.id" :name="u.name" :src="u.avatar_url" size="size-7 text-[10px] ring-2 ring-bg" />
      </span>
      <span class="size-2 rounded-full bg-success shadow-[0_0_8px] shadow-success" />
      <span class="tabular">{{ online.length }}</span>
    </button>
  </div>

  <Modal :open="listOpen" :title="t('online.title')" @close="listOpen = false">
    <ul class="space-y-1">
      <li v-for="u in online" :key="u.id" class="flex items-center gap-3 rounded-2xl p-2">
        <span class="relative">
          <Avatar :name="u.name" :src="u.avatar_url" />
          <span class="absolute -bottom-0.5 -end-0.5 size-3 rounded-full bg-success ring-2 ring-elevated" />
        </span>
        <span class="min-w-0 flex-1">
          <span class="block truncate font-semibold">{{ u.name }}<span v-if="u.is_me" class="ms-1 text-xs font-normal text-muted">({{ t('online.you') }})</span></span>
          <span v-if="!u.now_playing" class="block truncate text-sm text-muted" dir="ltr">@{{ u.username }}</span>
          <!-- What they are listening to; tap to listen along. -->
          <button
            v-else
            class="mt-1 flex w-full items-center gap-2 rounded-xl bg-fg/[0.06] p-1.5 pe-2 text-start transition hover:bg-fg/10 active:scale-[0.98]"
            :disabled="u.is_me"
            @click="listenAlong(u.now_playing)"
          >
            <TrackCover :track="{ ...u.now_playing, kind: 'remote' }" size="sm" rounded="rounded-md" />
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-1.5 text-[11px] font-semibold text-accent"><PlayingBars class="h-2.5" />{{ t('online.listening') }}</span>
              <span class="block truncate text-sm font-semibold">{{ u.now_playing.title }}</span>
              <span class="block truncate text-xs text-muted">{{ u.now_playing.artist ?? t('track.unknownArtist') }}</span>
            </span>
            <Play v-if="!u.is_me" class="size-5 shrink-0 fill-current text-fg" />
          </button>
        </span>
      </li>
    </ul>
  </Modal>
</template>
