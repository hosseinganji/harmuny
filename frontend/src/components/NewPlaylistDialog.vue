<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '@/lib/api'
import { t } from '@/lib/i18n'
import { usePlaylists } from '@/stores/playlists'
import Modal from './Modal.vue'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()
const playlists = usePlaylists()
const router = useRouter()

const name = ref('')
const description = ref('')
const isPublic = ref(false)
const busy = ref(false)
const error = ref('')

watch(() => props.open, (o) => {
  if (o) {
    name.value = ''
    description.value = ''
    isPublic.value = false
    error.value = ''
  }
})

async function submit() {
  busy.value = true
  try {
    const p = await playlists.create({ name: name.value.trim(), description: description.value.trim() || undefined, is_public: isPublic.value })
    emit('close')
    router.push({ name: 'playlist', params: { id: p.id } })
  } catch (e) {
    error.value = e instanceof ApiError ? e.first : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :open="open" :title="t('playlist.new')" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label class="label">{{ t('playlist.name') }}</label>
        <input v-model="name" class="input" :placeholder="t('playlist.namePlaceholder')" required maxlength="100" autofocus />
      </div>
      <div>
        <label class="label">{{ t('playlist.description') }}</label>
        <textarea v-model="description" class="input h-24 resize-none py-3" maxlength="500" />
      </div>
      <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl bg-fg/5 p-4">
        <div>
          <div class="font-semibold">{{ t('playlist.public') }}</div>
          <div class="text-sm text-muted">{{ t('playlist.publicHint') }}</div>
        </div>
        <input v-model="isPublic" type="checkbox" class="size-5 accent-[var(--accent)]" />
      </label>
      <p v-if="error" class="text-sm text-danger">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy || !name.trim()">{{ t('common.create') }}</button>
    </form>
  </Modal>
</template>
