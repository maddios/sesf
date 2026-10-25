<script setup lang="ts">
import { computed } from 'vue';
import { flattenMessages, type BannerMessages } from './messages';

const props = withDefaults(
  defineProps<{
    messages?: BannerMessages;
    variant?: 'error' | 'success' | 'info' | 'warning';
  }>(),
  { messages: null, variant: 'error' },
);

const items = computed(() => flattenMessages(props.messages));

const classes = computed(() => {
  switch (props.variant) {
    case 'success':
      return 'border-green-300 bg-green-50 text-green-900';
    case 'info':
      return 'border-blue-200 bg-blue-50 text-blue-900';
    case 'warning':
      return 'border-amber-300 bg-amber-50 text-amber-900';
    default:
      return 'border-red-300 bg-red-50 text-red-900';
  }
});
</script>

<template>
  <div
    v-if="items.length > 0"
    :role="variant === 'error' ? 'alert' : 'status'"
    aria-live="polite"
    class="rounded-md border px-4 py-3 text-sm"
    :class="classes"
  >
    <p
      v-for="(item, index) in items"
      :key="index"
    >
      {{ item }}
    </p>
  </div>
</template>
