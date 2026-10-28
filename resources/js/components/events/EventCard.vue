<script setup lang="ts">
/**
 * One event in the Current / Past lists: image, name (linked), '(Ended)' tag, short description, dates,
 * location, registration state and the Event Info and Gallery links.
 */
import { computed, useId } from 'vue';
import { RouterLink } from 'vue-router';
import type { CarShow } from '../../api/events';
import SafeImage from '../ui/SafeImage.vue';
import { formatEventRange, plainText } from './format';

const props = defineProps<{ show: CarShow }>();

const headingId = useId();
const dates = computed(() => formatEventRange(props.show.dateStart, props.show.dateEnd));
const galleryPath = computed(() => `/gallery/e${props.show.id}`);
const summary = computed(() => plainText(props.show.shortDescription));
</script>

<template>
  <article
    class="grid gap-5 border-b border-border py-6 last:border-b-0 sm:grid-cols-[minmax(0,200px)_1fr]"
    :aria-labelledby="headingId"
  >
    <RouterLink
      :to="show.path"
      class="hidden self-start no-underline sm:block"
      tabindex="-1"
      aria-hidden="true"
    >
      <SafeImage
        :src="show.imageUrl"
        resize="eventCard"
        alt=""
        :placeholder="show.shortName || show.name"
        width="200"
        height="184"
        img-class="aspect-[240/221] w-full rounded-sm border border-border object-cover"
      />
    </RouterLink>
    <div class="min-w-0">
      <h3
        :id="headingId"
        class="item-title"
      >
        <RouterLink
          :to="show.path"
          class="text-brand no-underline hover:underline"
        >
          {{ show.name }}
        </RouterLink>
        <span
          v-if="show.ended"
          class="ml-2 inline-block rounded-sm bg-subtle px-2 py-0.5 align-middle text-xs font-semibold text-muted"
        >(Ended)</span>
      </h3>
      <p
        v-if="summary"
        class="mt-3 text-[0.9375rem]"
      >
        {{ summary }}
      </p>

      <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <template v-if="show.locationLabel">
          <dt class="font-semibold text-title">
            Location
          </dt>
          <dd>{{ show.locationLabel }}</dd>
        </template>
        <dt class="font-semibold text-title">
          Event dates
        </dt>
        <dd>{{ dates || 'To be announced' }}</dd>
        <dt class="font-semibold text-title">
          Registration
        </dt>
        <dd>{{ show.registrationOpen ? 'Open' : 'Closed' }}</dd>
      </dl>

      <ul class="mt-4 flex flex-wrap gap-x-5 gap-y-2">
        <li>
          <RouterLink :to="show.path">
            Event Info <span class="sr-only">for {{ show.name }}</span>
          </RouterLink>
        </li>
        <li>
          <RouterLink :to="galleryPath">
            Gallery <span class="sr-only">for {{ show.name }}</span>
          </RouterLink>
        </li>
      </ul>
    </div>
  </article>
</template>
