<script setup lang="ts">
/**
 * Dollar amount as a decimal string ("12.50"), never a float. Typing is free; on blur a valid amount is normalised
 * to 2 places and emitted. An invalid entry is kept as typed, marked aria-invalid and reported through `invalid`.
 * Attributes (id, aria-describedby from FormField) go on the <input>.
 */
import { ref, watch } from 'vue';
import { normaliseMoney } from './format';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{ modelValue: string | null; max?: string; disabled?: boolean }>(), {
  max: '99999.99',
  disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: string | null]; invalid: [message: string | null] }>();

const text = ref(props.modelValue ?? '');
const invalid = ref(false);

watch(
  () => props.modelValue,
  (value) => {
    if (value !== normaliseMoney(text.value)) {
      text.value = value ?? '';
    }
  },
);

function onBlur(): void {
  if (text.value.trim() === '') {
    invalid.value = false;
    emit('invalid', null);
    emit('update:modelValue', null);
    return;
  }
  const value = normaliseMoney(text.value);
  if (value === null || Number(value) > Number(props.max)) {
    invalid.value = true;
    emit('invalid', value === null ? 'Enter an amount like 12.50.' : `The amount may not be more than ${props.max}.`);
    return;
  }
  invalid.value = false;
  text.value = value;
  emit('invalid', null);
  emit('update:modelValue', value);
}
</script>

<template>
  <span class="relative flex items-center">
    <span
      class="pointer-events-none absolute left-2.5 text-sm text-muted"
      aria-hidden="true"
    >$</span>
    <input
      v-bind="$attrs"
      v-model="text"
      type="text"
      inputmode="decimal"
      autocomplete="off"
      class="form-control w-full pl-6"
      :aria-invalid="invalid || $attrs['aria-invalid'] === true ? 'true' : undefined"
      :disabled="disabled"
      @blur="onBlur"
    >
  </span>
</template>
