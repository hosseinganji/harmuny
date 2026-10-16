import { registerPlugin } from '@capacitor/core'

export interface NativeAudioFile {
  id: string
  path: string
  title: string | null
  artist: string | null
  album: string | null
  duration: number // milliseconds
  size: number
  mime: string | null
  folder: string
  albumId: string | null
}

export interface LocalMusicPlugin {
  /** Query Android's MediaStore for every audio file on the device. */
  scan(): Promise<{ files: NativeAudioFile[] }>
  /** Album artwork as base64 JPEG, if the album has any. */
  artwork(options: { albumId: string }): Promise<{ data: string | null }>
}

export const LocalMusic = registerPlugin<LocalMusicPlugin>('LocalMusic')
