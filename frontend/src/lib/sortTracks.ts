import { ref, watch, type Ref } from 'vue'
import type { Track } from './types'

export type TrackSort = 'natural' | 'popular' | 'recent' | 'title' | 'artist' | 'duration'

const SORTS: TrackSort[] = ['natural', 'popular', 'recent', 'title', 'artist', 'duration']

function addedAt(track: Track): number {
  if (track.kind === 'local') return track.added_at
  return track.posted_at ? Date.parse(track.posted_at) : 0
}

/** Sort a song list. `plays` reads the freshest play count (see library.playsOf). */
export function sortTracks<T extends Track>(list: readonly T[], sort: TrackSort, plays: (track: T) => number): T[] {
  const out = [...list]
  switch (sort) {
    case 'popular': return out.sort((a, b) => plays(b) - plays(a))
    case 'recent': return out.sort((a, b) => addedAt(b) - addedAt(a))
    case 'title': return out.sort((a, b) => a.title.localeCompare(b.title))
    case 'artist': return out.sort((a, b) => (a.artist ?? '').localeCompare(b.artist ?? '') || a.title.localeCompare(b.title))
    case 'duration': return out.sort((a, b) => b.duration - a.duration)
    default: return out
  }
}

/** A sort choice remembered per list (on this device). */
export function useTrackSort(key: string, fallback: TrackSort): Ref<TrackSort> {
  const stored = localStorage.getItem(`smp.sort.${key}`) as TrackSort | null
  const sort = ref<TrackSort>(stored && SORTS.includes(stored) ? stored : fallback)
  watch(sort, (s) => localStorage.setItem(`smp.sort.${key}`, s))
  return sort
}
