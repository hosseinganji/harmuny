import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import type { Track } from '@/lib/types'

export interface Toast {
  id: number
  message: string
  kind: 'info' | 'success' | 'error'
  action?: { label: string; run: () => void }
}

export interface TrackMenuContext {
  playlistId?: number
  entryId?: number
  canRemoveFromPlaylist?: boolean
  queueUid?: string
}

export const useUi = defineStore('ui', () => {
  const toasts = ref<Toast[]>([])
  let nextId = 1

  function toast(message: string, kind: Toast['kind'] = 'info', action?: Toast['action']) {
    const id = nextId++
    toasts.value.push({ id, message, kind, action })
    setTimeout(() => dismiss(id), action ? 6000 : 3200)
  }
  function dismiss(id: number) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  // Track action menu (popover on desktop, bottom sheet on mobile).
  const menu = shallowRef<{ tracks: Track[]; x: number; y: number; context: TrackMenuContext } | null>(null)
  function openTrackMenu(tracks: Track | Track[], event?: MouseEvent | { x: number; y: number }, context: TrackMenuContext = {}) {
    const list = Array.isArray(tracks) ? tracks : [tracks]
    const x = event && 'clientX' in event ? event.clientX : (event?.x ?? innerWidth / 2)
    const y = event && 'clientY' in event ? event.clientY : (event?.y ?? innerHeight / 2)
    menu.value = { tracks: list, x, y, context }
  }

  const addToPlaylist = shallowRef<Track[] | null>(null)
  const editTrack = shallowRef<Track | null>(null)
  /** The song whose details sheet is open. */
  const trackInfo = shallowRef<Track | null>(null)

  const nowPlayingOpen = ref(false)
  const drawerOpen = ref(false)
  const sidePanel = ref<'queue' | 'lyrics' | null>((localStorage.getItem('smp.sidePanel') as 'queue' | 'lyrics' | null) ?? null)
  function toggleSidePanel(panel: 'queue' | 'lyrics') {
    sidePanel.value = sidePanel.value === panel ? null : panel
    if (sidePanel.value) localStorage.setItem('smp.sidePanel', sidePanel.value)
    else localStorage.removeItem('smp.sidePanel')
  }

  return {
    toasts, toast, dismiss,
    menu, openTrackMenu,
    addToPlaylist, editTrack, trackInfo,
    nowPlayingOpen, drawerOpen, sidePanel, toggleSidePanel,
  }
})
