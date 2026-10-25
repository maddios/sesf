<script setup lang="ts">
/**
 * One numbered step of the registration form: a fieldset whose legend is the step heading, with the server
 * messages for that section shown at its top. The wrapper takes focus after a failed submit.
 */
import ResponseBanner from '../ResponseBanner.vue';

defineProps<{
  domId: string;
  title: string;
  step: number;
  hint?: string;
  messages?: string[];
}>();
</script>

<template>
  <fieldset
    :id="domId"
    tabindex="-1"
    class="min-w-0 space-y-4 rounded-md border border-border bg-panel p-4 focus:outline-none sm:p-5"
  >
    <legend class="px-1">
      <h2 class="section-heading">
        {{ step }}. {{ title }}
      </h2>
    </legend>
    <p
      v-if="hint"
      class="text-sm text-muted"
    >
      {{ hint }}
    </p>
    <ResponseBanner :messages="messages ?? []" />
    <slot />
  </fieldset>
</template>
