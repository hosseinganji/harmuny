<script setup lang="ts">
import { computed } from 'vue'
import { encode } from 'uqr'

/** The invite link as a QR code: round dots and soft corner eyes, dark on white so any camera reads it. */
const props = defineProps<{ value: string }>()

const qr = computed(() => encode(props.value, { ecc: 'M', border: 0 }))

const eye = (r: number, c: number, size: number) => (r < 7 && c < 7) || (r < 7 && c >= size - 7) || (r >= size - 7 && c < 7)

const dots = computed(() => {
  const { size, data } = qr.value
  let path = ''
  for (let r = 0; r < size; r++) {
    for (let c = 0; c < size; c++) {
      if (data[r][c] && !eye(r, c, size)) path += `M${c + 0.5},${r + 0.08}a0.42,0.42 0 1,0 0.001,0z`
    }
  }
  return path
})

const eyes = computed(() => {
  const s = qr.value.size
  return [
    [0, 0],
    [s - 7, 0],
    [0, s - 7],
  ]
})
</script>

<template>
  <svg :viewBox="`-2 -2 ${qr.size + 4} ${qr.size + 4}`" class="block" role="img">
    <rect x="-2" y="-2" :width="qr.size + 4" :height="qr.size + 4" rx="3" fill="#fff" />
    <path :d="dots" fill="#0b0b12" />
    <g v-for="([x, y], i) in eyes" :key="i">
      <rect :x="x + 0.5" :y="y + 0.5" width="6" height="6" rx="1.8" fill="none" stroke="#0b0b12" stroke-width="1" />
      <rect :x="x + 2" :y="y + 2" width="3" height="3" rx="0.9" fill="var(--accent)" />
    </g>
  </svg>
</template>
