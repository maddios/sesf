<script setup lang="ts">
/**
 * Loading / error-with-retry wrapper for one data block. The default slot renders once the data is ready.
 * Empty results are the caller's job (they need their own copy).
 */
import BaseAlert from '../ui/BaseAlert.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseSpinner from '../ui/BaseSpinner.vue';
import type { AsyncStatus } from '../../composables/useAsyncData';

withDefaults(
  defineProps<{
    status: AsyncStatus;
    loadingLabel?: string;
    errorMessage?: string;
  }>(),
  { loadingLabel: 'Loading', errorMessage: 'This information could not be loaded right now.' },
);

const emit = defineEmits<{ retry: [] }>();
</script>

<template>
  <BaseSpinner
    v-if="status === 'loading'"
    :label="loadingLabel"
    class="py-6 text-brand"
  />
  <BaseAlert
    v-else-if="status === 'error'"
    variant="error"
  >
    <p>{{ errorMessage }}</p>
    <BaseButton
      variant="link"
      @click="emit('retry')"
    >
      Try again
    </BaseButton>
  </BaseAlert>
  <slot v-else />
</template>
