import { Capacitor } from '@capacitor/core'
import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { isNative } from '@/lib/config'
import { db } from '@/lib/db'
import { t } from '@/lib/i18n'
import { LocalMusic } from '@/lib/native'
import type { LocalFolder, LocalTrack, LocalTrackRecord } from '@/lib/types'
import { useUi } from './ui'

const AUDIO_EXT = /\.(mp3|m4a|aac|flac|ogg|oga|opus|wav|weba|webm|alac|aiff?)$/i

type DirPicker = (options?: { mode?: 'read' | 'readwrite'; id?: string }) => Promise<FileSystemDirectoryHandle>

export const supportsFolderAccess = typeof (window as any).showDirectoryPicker === 'function'
export const supportsNativeScan = isNative && Capacitor.getPlatform() === 'android'

function strip(record: LocalTrackRecord): LocalTrack {
  const { handle: _h, blob: _b, path: _p, cover: _c, ...track } = record
  return track
}

async function hashId(...parts: (string | number)[]): Promise<string> {
  const data = new TextEncoder().encode(parts.join('|'))
  const digest = await crypto.subtle.digest('SHA-1', data)
  return [...new Uint8Array(digest)].slice(0, 10).map((b) => b.toString(16).padStart(2, '0')).join('')
}

/** Music that lives on this device: picked folders (desktop), imported files (mobile web) or a MediaStore scan (Android app). */
export const useLocal = defineStore('local', () => {
  const tracks = shallowRef<LocalTrack[]>([])
  const folders = ref<LocalFolder[]>([])
  const scanning = ref<{ done: number; total: number } | null>(null)
  const coverUrls = new Map<string, string>()
  let loaded = false

  const byFolder = computed(() => {
    const map = new Map<string, LocalTrack[]>()
    for (const track of tracks.value) {
      map.set(track.folder, [...(map.get(track.folder) ?? []), track])
    }
    return map
  })

  async function load(force = false) {
    if (loaded && !force) return
    loaded = true
    folders.value = await db.localFolders.toArray()
    const rows = await db.localTracks.toArray()
    tracks.value = rows.map(strip).sort((a, b) => a.title.localeCompare(b.title))
  }

  async function readTags(file: Blob, fileName: string) {
    try {
      const { parseBlob } = await import('music-metadata')
      const meta = await parseBlob(file, { duration: false, skipPostHeaders: true })
      const picture = meta.common.picture?.[0]
      return {
        title: meta.common.title?.trim() || fileName.replace(/\.[^.]+$/, ''),
        artist: meta.common.artist?.trim() || null,
        album: meta.common.album?.trim() || null,
        genre: meta.common.genre?.[0] ?? null,
        year: meta.common.year ?? null,
        duration: Math.round(meta.format.duration ?? 0),
        cover: picture ? new Blob([new Uint8Array(picture.data)], { type: picture.format }) : undefined,
      }
    } catch {
      return { title: fileName.replace(/\.[^.]+$/, ''), artist: null, album: null, genre: null, year: null, duration: 0, cover: undefined }
    }
  }

  async function ingest(
    folder: LocalFolder,
    entries: { file: File; path: string; handle?: FileSystemFileHandle }[],
    source: 'handle' | 'blob',
  ) {
    scanning.value = { done: 0, total: entries.length }
    const records: LocalTrackRecord[] = []
    let index = 0
    // A few files at a time keeps tag parsing from freezing the UI.
    async function worker() {
      while (index < entries.length) {
        const { file, path, handle } = entries[index++]
        const tags = await readTags(file, file.name)
        const dir = path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : folder.name
        records.push({
          kind: 'local',
          id: await hashId(folder.id, path, file.size),
          ...tags,
          has_cover: !!tags.cover,
          file_name: file.name,
          file_size: file.size,
          mime_type: file.type || null,
          folder: dir,
          folder_id: folder.id,
          source,
          added_at: Date.now(),
          handle,
          blob: source === 'blob' ? file : undefined,
        })
        scanning.value = { done: scanning.value!.done + 1, total: entries.length }
      }
    }
    await Promise.all(Array.from({ length: 4 }, worker))

    await db.transaction('rw', db.localTracks, db.localFolders, async () => {
      if (source === 'handle') await db.localTracks.where('folder_id').equals(folder.id).delete()
      await db.localTracks.bulkPut(records)
      folder.count = await db.localTracks.where('folder_id').equals(folder.id).count()
      await db.localFolders.put(folder)
    })
    scanning.value = null
    await load(true)
    useUi().toast(t('local.added', { n: records.length }), 'success')
  }

  async function walk(dir: FileSystemDirectoryHandle, prefix: string, out: { file: File; path: string; handle: FileSystemFileHandle }[]) {
    for await (const entry of (dir as any).values() as AsyncIterable<FileSystemHandle>) {
      const path = prefix ? `${prefix}/${entry.name}` : entry.name
      if (entry.kind === 'directory') await walk(entry as FileSystemDirectoryHandle, path, out)
      else if (AUDIO_EXT.test(entry.name)) {
        const handle = entry as FileSystemFileHandle
        out.push({ file: await handle.getFile(), path, handle })
      }
    }
  }

  /** Desktop Chrome/Edge: remember a folder and read files in place. */
  async function addFolder() {
    const picker = (window as any).showDirectoryPicker as DirPicker
    const handle = await picker({ mode: 'read', id: 'music' })
    const existing = folders.value.find((f) => f.kind === 'handle' && f.name === handle.name)
    const folder: LocalFolder = existing ?? { id: await hashId('dir', handle.name, Date.now()), name: handle.name, kind: 'handle', handle, added_at: Date.now(), count: 0 }
    folder.handle = handle
    await scanFolder(folder)
  }

  async function scanFolder(folder: LocalFolder) {
    if (!folder.handle) return
    if (!(await ensurePermission(folder.handle))) return
    scanning.value = { done: 0, total: 0 }
    const entries: { file: File; path: string; handle: FileSystemFileHandle }[] = []
    await walk(folder.handle, folder.name, entries)
    await ingest(folder, entries, 'handle')
  }

  /** Mobile browsers: copy picked files into app storage. */
  async function importFiles(files: FileList | File[]) {
    const audio = [...files].filter((f) => AUDIO_EXT.test(f.name) || f.type.startsWith('audio/'))
    if (!audio.length) return
    const root = (audio[0] as any).webkitRelativePath?.split('/')[0] || t('local.imported')
    const existing = folders.value.find((f) => f.kind === 'import' && f.name === root)
    const folder: LocalFolder = existing ?? { id: await hashId('import', root, Date.now()), name: root, kind: 'import', added_at: Date.now(), count: 0 }
    await ingest(folder, audio.map((file) => ({ file, path: (file as any).webkitRelativePath || `${root}/${file.name}` })), 'blob')
  }

  /** Android app: list every song MediaStore knows about. */
  async function nativeScan() {
    scanning.value = { done: 0, total: 0 }
    try {
      const { files } = await LocalMusic.scan()
      const folder: LocalFolder = { id: 'native', name: t('local.deviceStorage'), kind: 'native', added_at: Date.now(), count: files.length }
      const records: LocalTrackRecord[] = files.map((f) => ({
        kind: 'local',
        id: 'n' + f.id,
        title: f.title || f.path.split('/').pop()!.replace(/\.[^.]+$/, ''),
        artist: f.artist && f.artist !== '<unknown>' ? f.artist : null,
        album: f.album,
        genre: null,
        year: null,
        duration: Math.round(f.duration / 1000),
        file_name: f.path.split('/').pop()!,
        file_size: f.size,
        mime_type: f.mime,
        folder: f.folder,
        folder_id: 'native',
        source: 'native',
        has_cover: !!f.albumId,
        added_at: Date.now(),
        path: f.path,
        cover: undefined,
        ...(f.albumId ? { albumId: f.albumId } : {}),
      }))
      await db.transaction('rw', db.localTracks, db.localFolders, async () => {
        await db.localTracks.where('folder_id').equals('native').delete()
        await db.localTracks.bulkPut(records)
        await db.localFolders.put(folder)
      })
      await load(true)
      useUi().toast(t('local.added', { n: records.length }), 'success')
    } finally {
      scanning.value = null
    }
  }

  async function ensurePermission(handle: FileSystemHandle): Promise<boolean> {
    const h = handle as any
    if ((await h.queryPermission?.({ mode: 'read' })) === 'granted') return true
    return (await h.requestPermission?.({ mode: 'read' })) === 'granted'
  }

  async function removeFolder(folder: LocalFolder) {
    await db.localTracks.where('folder_id').equals(folder.id).delete()
    await db.localFolders.delete(folder.id)
    await load(true)
  }

  async function removeTrack(track: LocalTrack) {
    await db.localTracks.delete(track.id)
    tracks.value = tracks.value.filter((t) => t.id !== track.id)
  }

  /** Something the <audio> element can play. */
  async function source(track: LocalTrack): Promise<string> {
    const record = await db.localTracks.get(track.id)
    if (!record) throw new Error('missing')
    if (record.source === 'native' && record.path) return Capacitor.convertFileSrc('file://' + record.path)
    if (record.source === 'handle' && record.handle) {
      if (!(await ensurePermission(record.handle))) throw new Error('permission')
      return URL.createObjectURL(await record.handle.getFile())
    }
    if (record.blob) return URL.createObjectURL(record.blob)
    throw new Error('missing')
  }

  async function cover(track: LocalTrack): Promise<string | null> {
    if (!track.has_cover) return null
    const cached = coverUrls.get(track.id)
    if (cached) return cached
    const record = (await db.localTracks.get(track.id)) as (LocalTrackRecord & { albumId?: string }) | undefined
    let blob = record?.cover
    if (!blob && record?.albumId && supportsNativeScan) {
      const { data } = await LocalMusic.artwork({ albumId: record.albumId }).catch(() => ({ data: null }))
      if (data) {
        blob = await (await fetch('data:image/jpeg;base64,' + data)).blob()
        await db.localTracks.update(track.id, { cover: blob })
      }
    }
    if (!blob) return null
    const url = URL.createObjectURL(blob)
    coverUrls.set(track.id, url)
    return url
  }

  return {
    tracks, folders, scanning, byFolder,
    load, addFolder, scanFolder, importFiles, nativeScan, removeFolder, removeTrack, source, cover,
  }
})
