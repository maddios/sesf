<script setup lang="ts">
/**
 * Native date picker. v-model is "YYYY-MM-DD" (or null when cleared); a server datetime is accepted and trimmed.
 * Attributes (id, aria-describedby from FormField) go on the <input>.
 */
import { computed } from 'vue';
import { toDateInput } from './format';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{ modelValue: string | null; min?: string; max?: string; disabled?: boolean }>(), {
  min: undefined,
  max: undefined,
  disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const value = computed(() => toDateInput(props.modelValue));

function onInput(event: Event): void {
  const next = (event.target as HTMLInputElement).value;
  emit('update:modelValue', next === '' ? null : next);
}
</script>

<template>
  <input
    v-bind="$attrs"
    type="date"
    class="form-control w-full"
    :value="value"
    :min="min"
    :max="max"
    :disabled="disabled"
    @input="onInput"
  >
</template>
