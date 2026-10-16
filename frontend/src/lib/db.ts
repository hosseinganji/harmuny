import Dexie, { type Table } from 'dexie'
import type { LocalFolder, LocalTrackRecord, RemoteTrack } from './types'

export interface DownloadRecord {
  trackId: number
  blob: Blob
  size: number
  mime: string
  downloadedAt: number
}

export interface KvRecord {
  key: string
  value: unknown
  updatedAt: number
}

class MusicDB extends Dexie {
  tracks!: Table<RemoteTrack, number>
  downloads!: Table<DownloadRecord, number>
  localTracks!: Table<LocalTrackRecord, string>
  localFolders!: Table<LocalFolder, string>
  kv!: Table<KvRecord, string>

  constructor() {
    super('smart-music')
    this.version(1).stores({
      tracks: 'id, artist, posted_at',
      downloads: 'trackId, downloadedAt',
      localTracks: 'id, folder_id, artist',
      localFolders: 'id',
      kv: 'key',
    })
  }
}

export const db = new MusicDB()

export const kv = {
  async get<T>(key: string): Promise<T | undefined> {
    return (await db.kv.get(key))?.value as T | undefined
  },
  async set(key: string, value: unknown) {
    await db.kv.put({ key, value: JSON.parse(JSON.stringify(value)), updatedAt: Date.now() })
  },
  async delete(key: string) {
    await db.kv.delete(key)
  },
}

/** Ask the browser not to evict our storage (downloads) under pressure. */
export async function requestPersistentStorage() {
  try {
    if (navigator.storage?.persist && !(await navigator.storage.persisted())) {
      await navigator.storage.persist()
    }
  } catch {
    // Not supported; nothing to do.
  }
}
