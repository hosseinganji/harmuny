export interface ApiTrack {
  id: number
  title: string
  artist: string | null
  album: string | null
  genre: string | null
  year: number | null
  duration: number
  file_size: number
  mime_type: string | null
  has_cover: boolean
  on_server?: boolean
  play_count: number
  channel_id: number | null
  posted_at: string | null
  updated_at: string | null
  liked?: boolean
  entry_id?: number
}

export interface RemoteTrack extends ApiTrack {
  kind: 'remote'
}

export type LocalSource = 'handle' | 'blob' | 'native'

export interface LocalTrack {
  kind: 'local'
  id: string
  title: string
  artist: string | null
  album: string | null
  genre: string | null
  year: number | null
  duration: number
  file_name: string
  file_size: number
  mime_type: string | null
  folder: string
  folder_id: string
  source: LocalSource
  has_cover: boolean
  added_at: number
}

export type Track = RemoteTrack | LocalTrack

/** Local track as stored in IndexedDB, with the data needed to play it. */
export interface LocalTrackRecord extends LocalTrack {
  handle?: FileSystemFileHandle
  blob?: Blob
  path?: string
  cover?: Blob
}

export interface LocalFolder {
  id: string
  name: string
  kind: 'handle' | 'import' | 'native'
  handle?: FileSystemDirectoryHandle
  added_at: number
  count: number
}

export interface User {
  id: number
  name: string
  username: string
  avatar_url: string | null
  bio: string | null
  role: 'admin' | 'member'
  email?: string | null
  status?: 'pending' | 'active' | 'disabled'
  created_at?: string
  last_seen_at?: string | null
  approved_at?: string | null
}

export interface Playlist {
  id: number
  name: string
  description: string | null
  is_public: boolean
  cover_url: string | null
  owner: { id: number; name?: string; username?: string }
  is_owner: boolean
  tracks_count?: number
  duration?: number
  preview_track_ids: number[]
  tracks?: ApiTrack[]
  created_at: string
  updated_at: string
}

export interface ArtistSummary {
  artist: string
  tracks_count: number
  plays?: number
  cover_track_id: number | null
}

export interface Lyrics {
  synced: string | null
  plain: string | null
  instrumental: boolean
  source: string | null
}

export interface Preferences {
  theme?: 'dark' | 'light' | 'system'
  dynamicColor?: boolean
  autoDownloadLiked?: boolean
}

export type RepeatMode = 'off' | 'all' | 'one'
