<script setup lang="ts">
/**
 * Label, control, hint and errors for one admin form field. Required fields show a red asterisk (the label also
 * says "(required)" to screen readers). The slot gets `inputProps` (id, aria-describedby, aria-invalid, required)
 * to bind on the control.
 */
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{ id: string; label: string; required?: boolean; errors?: string[]; hint?: string; wide?: boolean }>(),
  { required: false, errors: () => [], hint: undefined, wide: false },
);

const invalid = computed(() => props.errors.length > 0);
const describedBy = computed(() => {
  const ids = [props.hint ? `${props.id}-hint` : '', invalid.value ? `${props.id}-error` : ''].filter((id) => id !== '');
  return ids.length > 0 ? ids.join(' ') : undefined;
});
</script>

<template>
  <div
    class="space-y-1"
    :class="wide ? 'is-wide' : ''"
  >
    <label
      :for="id"
      class="admin-form-label block"
      :class="required ? 'is-required' : ''"
    >{{ label }}<span
      v-if="required"
      class="sr-only"
    > (required)</span></label>
    <slot :input-props="{ id, 'aria-describedby': describedBy, 'aria-invalid': invalid, 'aria-required': required || undefined }" />
    <p
      v-if="hint"
      :id="`${id}-hint`"
      class="text-xs text-muted"
    >
      {{ hint }}
    </p>
    <p
      v-for="(message, index) in errors"
      :id="index === 0 ? `${id}-error` : undefined"
      :key="index"
      class="admin-field-error"
    >
      {{ message }}
    </p>
  </div>
</template>
