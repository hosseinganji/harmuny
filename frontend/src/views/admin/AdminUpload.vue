<script setup lang="ts">
import { CircleCheck, CloudUpload, Copy, Loader2, Music2, TriangleAlert, X } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { auth } from '@/lib/api'
import { apiBase } from '@/lib/config'
import { bytes } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { ApiTrack } from '@/lib/types'
import { useLibrary } from '@/stores/library'

/**
 * Admins upload songs straight to the server. Title and artist come from the file's tags (editable before
 * sending); the server turns away a song the library already has.
 */
interface Item {
  id: number
  file: File
  title: string
  artist: string
  cover: string | null
  status: 'ready' | 'uploading' | 'done' | 'duplicate' | 'error'
  progress: number
  error?: string
  track?: ApiTrack
}

const library = useLibrary()
const items = ref<Item[]>([])
const dragging = ref(false)
const busy = computed(() => items.value.some((i) => i.status === 'uploading'))
const ready = computed(() => items.value.filter((i) => i.status === 'ready' || i.status === 'error'))
let nextId = 0

async function add(files: FileList | File[] | null) {
  for (const file of [...(files ?? [])]) {
    if (!/^audio\//.test(file.type) && !/\.(mp3|m4a|aac|flac|ogg|opus|wav)$/i.test(file.name)) continue
    const base = file.name.replace(/\.[^.]+$/, '').replace(/_/g, ' ')
    const [guessArtist, guessTitle] = base.includes(' - ') ? base.split(' - ', 2) : ['', base]
    const item: Item = { id: ++nextId, file, title: guessTitle.trim(), artist: guessArtist.trim(), cover: null, status: 'ready', progress: 0 }
    items.value.push(item)
    try {
      const { parseBlob } = await import('music-metadata')
      const meta = await parseBlob(file, { duration: false, skipPostHeaders: true })
      const live = items.value.find((i) => i.id === item.id)
      if (!live) continue
      live.title = meta.common.title || live.title
      live.artist = meta.common.artist || live.artist
      const pic = meta.common.picture?.[0]
      if (pic) live.cover = URL.createObjectURL(new Blob([pic.data as Uint8Array<ArrayBuffer>], { type: pic.format }))
    } catch {
      // No readable tags: the guess from the file name stays.
    }
  }
}

function drop(e: DragEvent) {
  dragging.value = false
  add(e.dataTransfer?.files ?? null)
}

/** One at a time, with progress (XMLHttpRequest reports upload progress; fetch doesn't). */
function upload(item: Item): Promise<void> {
  return new Promise((resolve) => {
    item.status = 'uploading'
    item.progress = 0
    const form = new FormData()
    form.append('file', item.file)
    form.append('title', item.title)
    form.append('artist', item.artist)
    const xhr = new XMLHttpRequest()
    xhr.open('POST', `${apiBase()}/api/admin/uploads`)
    xhr.setRequestHeader('Accept', 'application/json')
    if (auth.token) xhr.setRequestHeader('Authorization', `Bearer ${auth.token}`)
    xhr.upload.onprogress = (e) => e.lengthComputable && (item.progress = e.loaded / e.total)
    xhr.onload = () => {
      let body: any = null
      try {
        body = JSON.parse(xhr.responseText)
      } catch {
        // not JSON
      }
      if (xhr.status >= 200 && xhr.status < 300 && body?.data) {
        item.track = body.data
        item.status = body.duplicate ? 'duplicate' : 'done'
        if (!body.duplicate) library.remember([body.data])
      } else {
        item.status = 'error'
        item.error = xhr.status === 413 ? t('upload.tooBig') : (Object.values(body?.errors ?? {})[0] as string[] | undefined)?.[0] ?? body?.message ?? t('upload.failed')
      }
      resolve()
    }
    xhr.onerror = () => {
      item.status = 'error'
      item.error = t('toast.offlineAction')
      resolve()
    }
    xhr.send(form)
  })
}

async function uploadAll() {
  for (const item of ready.value) await upload(item)
  library.sync()
}

function remove(item: Item) {
  if (item.cover) URL.revokeObjectURL(item.cover)
  items.value = items.value.filter((i) => i !== item)
}
</script>

<template>
  <div class="space-y-4">
    <label
      class="flex cursor-pointer flex-col items-center justify-center gap-3 rounded-3xl border-2 border-dashed p-10 text-center transition"
      :class="dragging ? 'border-accent bg-accent/10' : 'border-fg/20 hover:border-accent/60 hover:bg-fg/[0.03]'"
      @dragover.prevent="dragging = true"
      @dragleave="dragging = false"
      @drop.prevent="drop"
    >
      <span class="flex size-16 items-center justify-center rounded-2xl bg-accent/15 text-accent"><CloudUpload class="size-8" /></span>
      <span class="text-lg font-bold">{{ t('upload.drop') }}</span>
      <span class="max-w-md text-sm text-muted">{{ t('upload.hint') }}</span>
      <input type="file" class="hidden" multiple accept="audio/*,.mp3,.m4a,.flac,.ogg,.opus,.wav" @change="add(($event.target as HTMLInputElement).files)" />
    </label>

    <ul v-if="items.length" class="space-y-2">
      <li v-for="item in items" :key="item.id" class="card flex items-center gap-3 p-3">
        <span class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-fg/5">
          <img v-if="item.cover" :src="item.cover" class="size-full object-cover" alt="" />
          <Music2 v-else class="size-6 text-subtle" />
        </span>
        <div class="min-w-0 flex-1 space-y-1">
          <template v-if="item.status === 'ready' || item.status === 'error'">
            <input v-model="item.title" class="input h-9 text-sm" :placeholder="t('upload.title')" />
            <input v-model="item.artist" class="input h-9 text-sm" :placeholder="t('upload.artist')" />
          </template>
          <template v-else>
            <div class="truncate font-semibold">{{ item.track?.title ?? item.title }}</div>
            <div class="truncate text-sm text-muted">{{ item.track?.artist ?? item.artist }}</div>
          </template>
          <div class="flex items-center gap-2 text-xs">
            <span class="text-subtle" dir="ltr">{{ bytes(item.file.size) }}</span>
            <span v-if="item.status === 'done'" class="flex items-center gap-1 font-semibold text-success"><CircleCheck class="size-3.5" />{{ t('upload.done') }}</span>
            <span v-else-if="item.status === 'duplicate'" class="flex items-center gap-1 font-semibold text-amber-400"><Copy class="size-3.5" />{{ t('upload.duplicate') }}</span>
            <span v-else-if="item.status === 'error'" class="flex items-center gap-1 font-semibold text-danger"><TriangleAlert class="size-3.5" />{{ item.error }}</span>
          </div>
          <div v-if="item.status === 'uploading'" class="h-1.5 overflow-hidden rounded-full bg-fg/10">
            <div class="h-full rounded-full bg-accent transition-[width]" :style="{ width: `${Math.round(item.progress * 100)}%` }" />
          </div>
        </div>
        <Loader2 v-if="item.status === 'uploading'" class="size-5 shrink-0 animate-spin text-accent" />
        <button v-else class="icon-btn size-9 shrink-0" :aria-label="t('common.remove')" @click="remove(item)"><X class="size-4" /></button>
      </li>
    </ul>

    <button v-if="ready.length" class="btn-primary h-12 w-full" :disabled="busy" @click="uploadAll">
      <Loader2 v-if="busy" class="size-4 animate-spin" /><CloudUpload v-else class="size-4" />{{ t('upload.send', { n: ready.length }) }}
    </button>
  </div>
</template>
