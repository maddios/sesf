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
      class="page-title"
    >
      Page not found
    </h1>
    <p class="mt-3">
      The page you are looking for does not exist or has moved.
    </p>
    <p class="mt-4">
      <RouterLink to="/">
        Go to the home page
      </RouterLink>
    </p>
  </section>
</template>
