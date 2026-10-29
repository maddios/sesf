<script setup lang="ts">
/**
 * Galleries / Event / ancestor galleries / current gallery (or "All" at the event level).
 */
import { RouterLink } from 'vue-router';
import type { GalleryBreadcrumb } from '../../api/gallery';

defineProps<{ breadcrumb: GalleryBreadcrumb; currentTitle: string | null }>();
</script>

<template>
  <nav
    aria-label="Breadcrumb"
    class="mb-4 text-sm"
  >
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
      <li>
        <RouterLink to="/gallery">
          Galleries
        </RouterLink>
      </li>
      <li class="flex items-center gap-2">
        <span
          aria-hidden="true"
          class="text-muted"
        >/</span>
        <RouterLink :to="`/gallery/e${breadcrumb.show.id}`">
          {{ breadcrumb.show.name }}
        </RouterLink>
      </li>
      <li
        v-for="crumb in breadcrumb.galleries"
        :key="crumb.id"
        class="flex items-center gap-2"
      >
        <span
          aria-hidden="true"
          class="text-muted"
        >/</span>
        <RouterLink :to="`/gallery/e${breadcrumb.show.id}/${crumb.id}`">
          {{ crumb.title }}
        </RouterLink>
      </li>
      <li class="flex items-center gap-2">
        <span
          aria-hidden="true"
          class="text-muted"
        >/</span>
        <span aria-current="page">{{ currentTitle ?? 'All' }}</span>
      </li>
    </ol>
  </nav>
</template>
