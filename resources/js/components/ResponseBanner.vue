<script setup lang="ts">
import { computed } from 'vue';
import BaseAlert from './ui/BaseAlert.vue';
import { flattenMessages, type BannerMessages } from './messages';

/** Server/validation messages in a BaseAlert. Renders nothing when there are no messages. */
const props = withDefaults(
  defineProps<{
    messages?: BannerMessages;
    variant?: 'error' | 'success' | 'info' | 'warning';
  }>(),
  { messages: null, variant: 'error' },
);

const items = computed(() => flattenMessages(props.messages));
</script>

<template>
  <BaseAlert
    v-if="items.length > 0"
    :variant="variant"
  >
    <p
      v-for="(item, index) in items"
      :key="index"
    >
      {{ item }}
    </p>
  </BaseAlert>
</template>
