import { ref } from 'vue'
import { api } from './api'

/**
 * New-song notifications (Web Push). iPhone only allows them for the app added to the home screen
 * (iOS 16.4+), so there the state says to install first.
 */
export type PushState = 'unsupported' | 'install' | 'denied' | 'off' | 'on'

const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent)
const standalone = matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true
const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

export const pushState = ref<PushState>('off')

function toKey(base64: string): ArrayBuffer {
  const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0)).buffer
}

export async function refreshPushState() {
  if (!supported) {
    pushState.value = isIOS && !standalone ? 'install' : 'unsupported'
    return
  }
  if (Notification.permission === 'denied') {
    pushState.value = 'denied'
    return
  }
  const registration = await navigator.serviceWorker.getRegistration()
  const subscription = await registration?.pushManager.getSubscription()
  pushState.value = subscription && Notification.permission === 'granted' ? 'on' : 'off'
}

/** Must be called from a tap: browsers only ask for permission in response to one. */
export async function enablePush(): Promise<boolean> {
  if (!supported) return false
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') {
    await refreshPushState()
    return false
  }
  const registration = await navigator.serviceWorker.ready
  const { public_key } = await api.get<{ public_key: string }>('/push/key')
  const subscription =
    (await registration.pushManager.getSubscription()) ??
    (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(public_key) }))
  const json = subscription.toJSON()
  const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings
  await api.post('/push/subscriptions', { endpoint: json.endpoint, keys: json.keys, content_encoding: encodings?.[0] ?? 'aes128gcm' })
  pushState.value = 'on'
  return true
}

export async function disablePush() {
  const registration = await navigator.serviceWorker.getRegistration()
  const subscription = await registration?.pushManager.getSubscription()
  if (subscription) {
    await api.delete('/push/subscriptions', { endpoint: subscription.endpoint }).catch(() => {})
    await subscription.unsubscribe()
  }
  pushState.value = 'off'
}
