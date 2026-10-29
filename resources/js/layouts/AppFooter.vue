<script setup lang="ts">
/** Site footer: copyright, Privacy Policy, and Contact Us (emits `contact`; the contact form opens from it). */
import { RouterLink } from 'vue-router';

const emit = defineEmits<{ contact: [] }>();
const year = new Date().getFullYear();
</script>

<template>
  <footer class="mx-auto w-full max-w-site px-4 py-3 text-footer font-medium uppercase text-muted md:w-[calc(100%-2rem)] md:px-panel">
    <div class="flex flex-col items-center gap-3 text-center sm:flex-row sm:justify-between sm:text-left">
      <p>
        Southeast Sharkfest, Inc. &copy; {{ year }}
        <span aria-hidden="true">&bull;</span>
        <RouterLink
          to="/privacy-policy"
          class="text-muted no-underline hover:text-brand hover:underline"
        >
          Privacy Policy
        </RouterLink>
      </p>
      <button
        type="button"
        class="font-medium uppercase text-muted hover:text-brand hover:underline"
        @click="emit('contact')"
      >
        Contact Us
      </button>
    </div>
  </footer>
</template>
