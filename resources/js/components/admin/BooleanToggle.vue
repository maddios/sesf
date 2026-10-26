<script setup lang="ts">
/**
 * On/off switch (role="switch", aria-checked). Space/Enter toggle (native button). v-model is a boolean.
 * `label` names the switch; set `hideLabel` to keep it for screen readers only. `busy` disables it and sets
 * aria-busy while a save runs.
 */
import { useId } from 'vue';

const props = withDefaults(
  defineProps<{
    modelValue: boolean;
    label: string;
    hideLabel?: boolean;
    disabled?: boolean;
    busy?: boolean;
    id?: string;
  }>(),
  { hideLabel: false, disabled: false, busy: false, id: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
const labelId = useId();

function toggle(): void {
  if (!props.disabled && !props.busy) emit('update:modelValue', !props.modelValue);
}
</script>

<template>
  <span class="inline-flex items-center gap-2">
    <button
      :id="id"
      type="button"
      role="switch"
      :aria-checked="modelValue ? 'true' : 'false'"
      :aria-labelledby="labelId"
      :aria-busy="busy ? 'true' : undefined"
      :disabled="disabled || busy"
      class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition-colors disabled:cursor-not-allowed disabled:opacity-60"
      :class="modelValue ? 'border-brand bg-brand' : 'border-border-strong bg-subtle'"
      @click="toggle"
    >
      <span
        aria-hidden="true"
        class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform"
        :class="modelValue ? 'translate-x-6' : 'translate-x-1'"
      />
    </button>
    <span
      :id="labelId"
      class="text-sm text-text"
      :class="hideLabel ? 'sr-only' : ''"
    >{{ label }}</span>
  </span>
</template>
