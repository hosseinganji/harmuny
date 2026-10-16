<script setup lang="ts">
import { computed, ref } from 'vue'

const props = withDefaults(defineProps<{ value: number; buffered?: number; thick?: boolean; label?: string }>(), { buffered: 0 })
const emit = defineEmits<{ seek: [fraction: number]; preview: [fraction: number | null] }>()

const bar = ref<HTMLElement>()
const dragging = ref<number | null>(null)
const shown = computed(() => dragging.value ?? props.value)

function fractionAt(e: PointerEvent) {
  const rect = bar.value!.getBoundingClientRect()
  return Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width))
}

function down(e: PointerEvent) {
  bar.value!.setPointerCapture(e.pointerId)
  dragging.value = fractionAt(e)
  emit('preview', dragging.value)
}
function move(e: PointerEvent) {
  if (dragging.value === null) return
  dragging.value = fractionAt(e)
  emit('preview', dragging.value)
}
function up(e: PointerEvent) {
  if (dragging.value === null) return
  emit('seek', fractionAt(e))
  dragging.value = null
  emit('preview', null)
}
function key(e: KeyboardEvent) {
  const step = e.shiftKey ? 0.1 : 0.02
  if (e.key === 'ArrowRight') emit('seek', Math.min(1, props.value + step))
  else if (e.key === 'ArrowLeft') emit('seek', Math.max(0, props.value - step))
  else return
  e.preventDefault()
}
</script>

<template>
  <div
    ref="bar"
    dir="ltr"
    role="slider"
    tabindex="0"
    :aria-label="label"
    :aria-valuenow="Math.round(shown * 100)"
    aria-valuemin="0"
    aria-valuemax="100"
    class="group relative flex cursor-pointer touch-none items-center outline-none"
    :class="thick ? 'h-6' : 'h-4'"
    @pointerdown="down"
    @pointermove="move"
    @pointerup="up"
    @pointercancel="dragging = null"
    @keydown="key"
  >
    <div class="relative w-full overflow-hidden rounded-full bg-fg/15 transition-all" :class="[thick ? 'h-1.5' : 'h-1', dragging !== null ? 'h-1.5' : 'group-hover:h-1.5']">
      <div class="absolute inset-y-0 left-0 rounded-full bg-fg/15" :style="{ width: `${buffered * 100}%` }" />
      <div class="absolute inset-y-0 left-0 rounded-full bg-fg group-hover:bg-accent" :class="{ '!bg-accent': dragging !== null }" :style="{ width: `${shown * 100}%` }" />
    </div>
    <div
      class="pointer-events-none absolute size-3.5 -translate-x-1/2 rounded-full bg-white shadow-lg transition-opacity"
      :class="dragging !== null || thick ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100'"
      :style="{ left: `${shown * 100}%` }"
    />
  </div>
</template>
