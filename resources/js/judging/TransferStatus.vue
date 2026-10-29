<script setup lang="ts">
import { computed } from 'vue';
import { transferText, type TransferState } from './uploads';

const props = defineProps<{ state: TransferState }>();
defineEmits<{ stop: []; dismiss: [] }>();

const text = computed(() => transferText(props.state));
</script>

<template>
  <section
    v-if="state.count > 0 || state.failed.length > 0"
    aria-label="Photo uploads"
    class="sticky top-0 z-20 rounded border p-3 text-sm"
    :class="state.stalled ? 'border-warning-fg bg-warning-bg text-warning-fg' : 'border-border bg-panel'"
  >
    <template v-if="state.count > 0">
      <div
        role="progressbar"
        aria-label="Photo upload progress"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="state.percent"
        class="h-2 w-full overflow-hidden rounded bg-subtle"
      >
        <div
          class="h-2 bg-brand"
          :style="{ width: `${Math.max(1, state.percent)}%` }"
        />
      </div>
      <p
        class="mt-2"
        aria-live="polite"
      >
        {{ text }}
      </p>
      <ul class="mt-1">
        <li
          v-for="file in state.files"
          :key="file.id"
        >
          {{ file.name }}: {{ file.percent }}%
        </li>
      </ul>
      <button
        type="button"
        class="mt-2 rounded border border-default-border bg-default px-3 py-1 hover:bg-default-hover"
        @click="$emit('stop')"
      >
        Stop photo uploads
      </button>
    </template>
    <div
      v-if="state.failed.length > 0"
      role="alert"
      class="mt-2 text-danger-fg"
    >
      <p>Upload failed — try again: {{ state.failed.join(', ') }}</p>
      <button
        type="button"
        class="mt-1 underline"
        @click="$emit('dismiss')"
      >
        Dismiss
      </button>
    </div>
  </section>
</template>
