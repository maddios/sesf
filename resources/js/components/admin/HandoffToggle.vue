<script setup lang="ts">
/**
 * The legacy T-shirt hand-out switch: a whole-row checkbox (orange while pending, green when handed out) with a
 * slider. Emits `toggle` with the wanted state; the parent saves it and passes the stored state back in `checked`.
 */
defineProps<{ label: string; checked: boolean; busy?: boolean; compact?: boolean }>();
const emit = defineEmits<{ toggle: [checked: boolean] }>();

/** The box shows the stored state until the parent confirms the change (a failed save must not leave it flipped). */
function onChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  const wanted = input.checked;
  input.checked = !wanted;
  emit('toggle', wanted);
}
</script>

<template>
  <label
    class="a2-handoff"
    :class="compact ? 'a2-handoff--compact' : ''"
  >
    <input
      type="checkbox"
      :checked="checked"
      :disabled="busy"
      :aria-label="`Handed out: ${label}`"
      @change="onChange"
    >
    <span class="a2-handoff-inner">
      <span class="a2-handoff-name">{{ label }}</span>
      <span
        class="a2-handoff-slider"
        aria-hidden="true"
      ><span class="a2-handoff-knob" /></span>
    </span>
  </label>
</template>
