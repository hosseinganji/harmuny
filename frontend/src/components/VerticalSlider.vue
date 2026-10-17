<script setup lang="ts">
import { computed, ref } from 'vue'

/** A touch-friendly vertical slider centred on zero (an EQ band). Double-tap resets it to 0. */
const props = withDefaults(defineProps<{ modelValue: number; min?: number; max?: number; step?: number; label?: string }>(), {
  min: -12,
  max: 12,
  step: 0.5,
})
const emit = defineEmits<{ 'update:modelValue': [value: number] }>()

const track = ref<HTMLElement>()
const active = ref(false)
const ratio = computed(() => (props.modelValue - props.min) / (props.max - props.min))
const zero = computed(() => (0 - props.min) / (props.max - props.min))

function valueAt(clientY: number) {
  const rect = track.value!.getBoundingClientRect()
  const r = 1 - Math.min(Math.max((clientY - rect.top) / rect.height, 0), 1)
  const v = props.min + r * (props.max - props.min)
  return Math.round(v / props.step) * props.step
}

function down(e: PointerEvent) {
  active.value = true
  track.value!.setPointerCapture(e.pointerId)
  emit('update:modelValue', valueAt(e.clientY))
}
function move(e: PointerEvent) {
  if (active.value) emit('update:modelValue', valueAt(e.clientY))
}
function up() {
  active.value = false
}

let lastTap = 0
function tap() {
  const now = Date.now()
  if (now - lastTap < 300) emit('update:modelValue', 0)
  lastTap = now
}

function key(e: KeyboardEvent) {
  const delta = e.key === 'ArrowUp' || e.key === 'ArrowRight' ? props.step : e.key === 'ArrowDown' || e.key === 'ArrowLeft' ? -props.step : 0
  if (!delta) return
  e.preventDefault()
  emit('update:modelValue', Math.min(props.max, Math.max(props.min, props.modelValue + delta)))
}
</script>

<template>
  <div
    ref="track"
    class="relative flex h-full w-full cursor-pointer touch-none justify-center outline-none"
    role="slider"
    tabindex="0"
    :aria-label="label"
    :aria-valuemin="min"
    :aria-valuemax="max"
    :aria-valuenow="modelValue"
    aria-orientation="vertical"
    @pointerdown="down"
    @pointermove="move"
    @pointerup="up"
    @pointercancel="up"
    @click="tap"
    @keydown="key"
  >
    <!-- Track, and the fill from the 0 dB line to the value -->
    <div class="absolute inset-y-0 w-1.5 rounded-full bg-fg/10" />
    <div
      class="absolute w-1.5 rounded-full bg-accent transition-[top,bottom] duration-75"
      :style="ratio >= zero ? { bottom: `${zero * 100}%`, top: `${(1 - ratio) * 100}%` } : { top: `${(1 - zero) * 100}%`, bottom: `${ratio * 100}%` }"
    />
    <div
      class="absolute size-5 -translate-y-1/2 rounded-full border-2 border-accent bg-white shadow-md transition-transform duration-150"
      :class="{ 'scale-125': active }"
      :style="{ top: `${(1 - ratio) * 100}%` }"
    />
  </div>
</template>
