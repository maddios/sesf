<script setup lang="ts">
/** Car card on a member profile (garage and attended events, legacy .thumbnail): photo and title, linking to the car. */
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import type { CarSummary } from '../../api/cars';
import SafeImage from '../ui/SafeImage.vue';

const props = defineProps<{ car: CarSummary }>();

const title = computed(() => props.car.nickname || [props.car.year, props.car.model].filter(Boolean).join(' ') || `Car ${props.car.id}`);
const path = computed(() => `/car/${props.car.id}`);
</script>

<template>
  <article class="overflow-hidden rounded-md bg-panel shadow-card">
    <RouterLink
      :to="path"
      class="block no-underline"
      tabindex="-1"
      aria-hidden="true"
    >
      <SafeImage
        :src="car.imageUrl"
        resize="memberCar"
        alt=""
        :placeholder="title"
        img-class="aspect-[300/220] w-full object-cover"
      />
    </RouterLink>
    <p class="p-2 text-[0.6875rem] font-bold text-heading">
      <RouterLink
        :to="path"
        class="text-heading no-underline hover:text-brand hover:underline"
      >
        {{ title }}
      </RouterLink>
    </p>
  </article>
</template>
