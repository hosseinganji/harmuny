<script setup lang="ts">
import { Check, Loader2 } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import Avatar from '@/components/Avatar.vue'
import Modal from '@/components/Modal.vue'
import { api, ApiError } from '@/lib/api'
import { t } from '@/lib/i18n'
import type { Member, Playlist } from '@/lib/types'
import { usePlaylists } from '@/stores/playlists'
import { useUi } from '@/stores/ui'

/** The owner picks which members may add songs to a playlist (making it a shared playlist). */
const props = defineProps<{ open: boolean; playlist: Playlist }>()
const emit = defineEmits<{ close: []; saved: [playlist: Playlist] }>()

const ui = useUi()
const playlists = usePlaylists()
const members = ref<Member[] | null>(null)
const chosen = ref(new Set<number>())
const busy = ref(false)
const filter = ref('')

watch(() => props.open, async (open) => {
  if (!open) return
  chosen.value = new Set((props.playlist.collaborators ?? []).map((c) => c.id))
  filter.value = ''
  try {
    members.value = (await api.get<{ data: Member[] }>('/members')).data
  } catch {
    members.value = []
  }
})

const shown = computed(() => {
  const q = filter.value.trim().toLowerCase()
  return (members.value ?? []).filter((m) => !q || m.name.toLowerCase().includes(q) || m.username.includes(q))
})

function toggle(id: number) {
  const next = new Set(chosen.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  chosen.value = next
}

async function save() {
  busy.value = true
  try {
    const res = await api.put<{ data: Playlist }>(`/playlists/${props.playlist.id}/collaborators`, { user_ids: [...chosen.value] })
    playlists.load()
    ui.toast(t('toast.saved'), 'success')
    emit('saved', res.data)
    emit('close')
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.first : t('toast.offlineAction'), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :open="open" :title="t('collab.title')" @close="emit('close')">
    <p class="mb-3 text-sm text-muted">{{ t('collab.hint') }}</p>
    <input v-if="(members?.length ?? 0) > 6" v-model="filter" class="input mb-3 h-10 rounded-full" :placeholder="t('library.filter')" />
    <div v-if="!members" class="flex justify-center py-8"><Loader2 class="size-6 animate-spin text-muted" /></div>
    <p v-else-if="!members.length" class="py-6 text-center text-sm text-subtle">{{ t('collab.noMembers') }}</p>
    <div v-else class="max-h-[45dvh] space-y-1 overflow-y-auto">
      <button
        v-for="m in shown"
        :key="m.id"
        class="flex w-full items-center gap-3 rounded-2xl p-2 text-start transition hover:bg-fg/5"
        :aria-pressed="chosen.has(m.id)"
        @click="toggle(m.id)"
      >
        <Avatar :name="m.name" :src="m.avatar_url" />
        <span class="min-w-0 flex-1">
          <span class="block truncate font-semibold">{{ m.name }}</span>
          <span class="block truncate text-xs text-muted" dir="ltr">@{{ m.username }}</span>
        </span>
        <span class="flex size-6 items-center justify-center rounded-full border-2 transition" :class="chosen.has(m.id) ? 'border-accent bg-accent text-white' : 'border-line'">
          <Check v-if="chosen.has(m.id)" class="size-4" />
        </span>
      </button>
    </div>
    <button class="btn-primary mt-4 w-full" :disabled="busy || !members" @click="save">{{ t('common.save') }}</button>
  </Modal>
</template>
