<script setup lang="ts">
/**
 * Native date-time picker. v-model is the server format "YYYY-MM-DD HH:MM:SS" (null when cleared). The browser
 * shows local wall-clock time without a zone, so a note states the zone the server stores (the app runs on
 * Eastern time). Attributes (id, aria-describedby) go on the <input>; the note is linked by aria-describedby.
 */
import { computed, useAttrs, useId } from 'vue';
import { fromDateTimeLocal, toDateTimeLocal } from './format';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{ modelValue: string | null; timezoneNote?: string; disabled?: boolean }>(), {
  timezoneNote: 'Times are Eastern (the event’s local time).',
  disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const attrs = useAttrs();
const noteId = useId();
const value = computed(() => toDateTimeLocal(props.modelValue));
const describedBy = computed(() => [attrs['aria-describedby'], noteId].filter((id) => typeof id === 'string' && id !== '').join(' '));

function onInput(event: Event): void {
  emit('update:modelValue', fromDateTimeLocal((event.target as HTMLInputElement).value));
}
</script>

<template>
  <span class="block space-y-1">
    <input
      v-bind="$attrs"
      type="datetime-local"
      class="form-control w-full"
      :value="value"
      :disabled="disabled"
      :aria-describedby="describedBy"
      @input="onInput"
    >
    <span
      :id="noteId"
      class="block text-xs text-muted"
    >{{ timezoneNote }}</span>
  </span>
</template>
