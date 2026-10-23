import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, request } from '@/lib/api'
import { cachedGet } from '@/lib/cached'
import { kv } from '@/lib/db'
import { t } from '@/lib/i18n'
import type { Playlist } from '@/lib/types'
import { useLibrary } from './library'
import { useUi } from './ui'

export const usePlaylists = defineStore('playlists', () => {
  const mine = ref<Playlist[]>([])
  const loaded = ref(false)

  async function load() {
    try {
      const res = await cachedGet<{ data: Playlist[] }>('/playlists', undefined, (cached) => {
        if (!loaded.value) mine.value = cached.data
      })
      mine.value = res.data
    } catch {
      // keep whatever we have
    }
    loaded.value = true
  }

  /** Playlist with tracks; falls back to the cached copy offline. */
  async function get(id: number, onCached?: (p: Playlist) => void): Promise<Playlist> {
    const res = await cachedGet<{ data: Playlist }>(`/playlists/${id}`, undefined, (c) => onCached?.(c.data))
    useLibrary().remember(res.data.tracks ?? [])
    return res.data
  }

  function upsert(p: Playlist) {
    const i = mine.value.findIndex((x) => x.id === p.id)
    const summary = { ...p, tracks: undefined, tracks_count: p.tracks?.length ?? p.tracks_count }
    if (i >= 0) mine.value.splice(i, 1, summary)
    else mine.value.unshift(summary)
    if (p.tracks) kv.set(`get:/playlists/${p.id}`, { data: p })
  }

  async function create(data: { name: string; description?: string; is_public?: boolean; track_ids?: number[] }) {
    const res = await api.post<{ data: Playlist }>('/playlists', data)
    upsert(res.data)
    useUi().toast(t('toast.playlistCreated', { name: res.data.name }), 'success')
    return res.data
  }

  async function update(id: number, data: Partial<Pick<Playlist, 'name' | 'description' | 'is_public'>>) {
    const res = await api.patch<{ data: Playlist }>(`/playlists/${id}`, data)
    upsert(res.data)
    return res.data
  }

  async function remove(id: number) {
    await api.delete(`/playlists/${id}`)
    mine.value = mine.value.filter((p) => p.id !== id)
    await kv.delete(`get:/playlists/${id}`)
  }

  async function addTracks(id: number, trackIds: number[]) {
    const res = await api.post<{ data: Playlist }>(`/playlists/${id}/tracks`, { track_ids: trackIds })
    upsert(res.data)
    useUi().toast(t('toast.addedToPlaylist', { name: res.data.name }), 'success')
    // Open views refresh, and an auto-downloading playlist fetches the new songs.
    window.dispatchEvent(new CustomEvent('playlist:changed', { detail: id }))
    return res.data
  }

  async function removeEntry(id: number, entryId: number) {
    const res = await api.delete<{ data: Playlist }>(`/playlists/${id}/tracks/${entryId}`)
    upsert(res.data)
    return res.data
  }

  async function reorder(id: number, entryIds: number[]) {
    const res = await api.put<{ data: Playlist }>(`/playlists/${id}/order`, { entry_ids: entryIds })
    upsert(res.data)
    return res.data
  }

  async function uploadCover(id: number, file: File) {
    const form = new FormData()
    form.append('cover', file)
    const res = await request<{ data: Playlist }>('POST', `/playlists/${id}/cover`, { body: form })
    upsert(res.data)
    return res.data
  }

  async function deleteCover(id: number) {
    const res = await api.delete<{ data: Playlist }>(`/playlists/${id}/cover`)
    upsert(res.data)
    return res.data
  }

  return { mine, loaded, load, get, create, update, remove, addTracks, removeEntry, reorder, uploadCover, deleteCover }
})
