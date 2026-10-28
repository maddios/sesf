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
    class="min-w-0 rounded-md border border-border bg-panel p-4 focus:outline-none sm:p-5"
  >
    <!-- Floated so the heading and its full-width rule sit inside the box instead of on its border. -->
    <legend class="float-left w-full">
      <h2 class="section-heading">
        {{ step }}. {{ title }}
      </h2>
    </legend>
    <div class="clear-both space-y-4">
      <p
        v-if="hint"
        class="text-sm text-muted"
      >
        {{ hint }}
      </p>
      <ResponseBanner :messages="messages ?? []" />
      <slot />
    </div>
  </fieldset>
</template>
