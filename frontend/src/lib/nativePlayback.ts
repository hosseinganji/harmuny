import { Capacitor, registerPlugin } from '@capacitor/core'
import { watch } from 'vue'
import { coverUrl } from './api'
import { isNative } from './config'
import { usePlayer } from '@/stores/player'

interface BackgroundPlayback {
  update(state: { title: string; artist: string; album: string; artwork: string | null; playing: boolean; position: number; duration: number }): Promise<void>
  stop(): Promise<void>
  addListener(event: 'action', listener: (e: { action: string; position: number }) => void): Promise<{ remove: () => void }>
}

const BackgroundPlayback = registerPlugin<BackgroundPlayback>('BackgroundPlayback')

/**
 * Android app: what plays is handed to the native playback service (PlaybackService.java), which keeps the
 * phone awake while music plays (so songs go on with the screen off) and shows the notification and the
 * lock screen, headphone and car controls; their buttons come back here.
 */
export function startNativePlayback() {
  if (!isNative || Capacitor.getPlatform() !== 'android') return
  const player = usePlayer()

  BackgroundPlayback.addListener('action', ({ action, position }) => {
    if (action === 'play') player.play()
    else if (action === 'pause') player.pause()
    else if (action === 'next') player.next()
    else if (action === 'prev') player.prev()
    else if (action === 'seek' && position >= 0) player.seek(position / 1000)
  })

  function report() {
    const track = player.track
    if (!track) return void BackgroundPlayback.stop().catch(() => {})
    BackgroundPlayback.update({
      title: track.title,
      artist: track.artist ?? '',
      album: track.album ?? player.context?.name ?? '',
      artwork: track.kind === 'remote' && track.has_cover ? coverUrl(track.id, track.updated_at) : null,
      playing: player.playing,
      position: Math.round(player.currentTime * 1000),
      duration: Math.round(player.duration * 1000),
    }).catch(() => {})
  }

  watch(() => [player.track, player.playing, Math.round(player.duration)], report)
  // Now and then, so the lock screen's progress bar stays right.
  watch(() => Math.floor(player.currentTime / 15), report)
}
