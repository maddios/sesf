<script setup lang="ts">
import { RouterLink } from 'vue-router';
</script>

<template>
  <section
    class="mx-auto max-w-2xl"
    aria-labelledby="not-found-heading"
  >
    <h1
      id="not-found-heading"
      class="text-2xl font-bold"
    >
      Page not found
    </h1>
    <p class="mt-2 text-slate-700">
      The page you are looking for does not exist or has moved.
    </p>
    <p class="mt-4">
      <RouterLink
        to="/"
        class="font-medium text-blue-800 underline underline-offset-2 hover:text-blue-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-800"
      >
        Go to the home page
      </RouterLink>
    </p>
  </section>
</template>
