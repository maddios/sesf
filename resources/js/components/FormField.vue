<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    errors?: string[];
    hint?: string;
  }>(),
  { errors: () => [], hint: undefined },
);

const hasErrors = computed(() => props.errors.length > 0);
const hintId = computed(() => `${props.id}-hint`);
const errorId = computed(() => `${props.id}-error`);
const describedBy = computed(() => {
  const ids = [props.hint ? hintId.value : '', hasErrors.value ? errorId.value : ''].filter((id) => id !== '');
  return ids.length > 0 ? ids.join(' ') : undefined;
});
</script>

<template>
  <div class="space-y-1">
    <label
      :for="id"
      class="block text-sm font-medium text-slate-800"
    >{{ label }}</label>
    <!-- The slot receives attributes to bind on the control: id, aria-describedby and aria-invalid. -->
    <slot
      :input-props="{
        id,
        'aria-describedby': describedBy,
        'aria-invalid': hasErrors,
      }"
    />
    <p
      v-if="hint"
      :id="hintId"
      class="text-sm text-slate-600"
    >
      {{ hint }}
    </p>
    <p
      v-for="(message, index) in errors"
      v-show="hasErrors"
      :id="index === 0 ? errorId : undefined"
      :key="index"
      class="text-sm text-red-800"
    >
      {{ message }}
    </p>
  </div>
</template>
