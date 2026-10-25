<script setup lang="ts">
/** One car in the car directory grid, linking to its car profile. */
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import type { CarRow } from '../../api/cars';
import { GENERIC_AVATAR_URL } from '../../api/members';
import SafeImage from '../ui/SafeImage.vue';

const props = defineProps<{ car: CarRow }>();

const title = computed(() => props.car.nickname || [props.car.year, props.car.model].filter(Boolean).join(' ') || `Car ${props.car.id}`);
const carPath = computed(() => `/car/${props.car.id}`);

const imageFailed = ref(false);
const imageSrc = computed(() =>
  imageFailed.value || props.car.imageUrl === '' ? GENERIC_AVATAR_URL : props.car.imageUrl,
);

function onImageError(): void {
  imageFailed.value = true;
}
</script>

<template>
  <li>
    <article class="flex h-full flex-col overflow-hidden rounded-md border border-border bg-panel shadow-card">
      <RouterLink
        :to="carPath"
        tabindex="-1"
        aria-hidden="true"
        class="block"
      >
        <SafeImage
          :src="imageSrc"
          :alt="title"
          img-class="aspect-[4/3] w-full object-cover"
          @error="onImageError"
        />
      </RouterLink>
      <div class="flex flex-1 flex-col gap-1 p-3">
        <h2 class="text-base font-semibold text-heading">
          <RouterLink
            :to="carPath"
            class="text-heading no-underline hover:text-brand hover:underline"
          >
            {{ title }}
          </RouterLink>
        </h2>
        <p
          v-if="car.nickname && (car.year || car.model)"
          class="text-sm text-muted"
        >
          {{ [car.year, car.model].filter(Boolean).join(' ') }}
        </p>
        <p
          v-if="car.owner"
          class="mt-auto pt-1 text-sm text-muted"
        >
          Owner: {{ car.owner.fullName }}
        </p>
      </div>
    </article>
  </li>
</template>
