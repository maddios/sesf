<script setup lang="ts">
/**
 * One event in the Current / Past lists: image, name (linked), '(Ended)' tag, short description, dates,
 * location, registration state and the Event Info and Gallery links.
 */
import { computed, useId } from 'vue';
import { RouterLink } from 'vue-router';
import type { CarShow } from '../../api/events';
import BaseButton from '../ui/BaseButton.vue';
import SafeImage from '../ui/SafeImage.vue';
import { formatEventDateTime, formatEventRange, plainText } from './format';

const props = defineProps<{ show: CarShow }>();

const headingId = useId();
const dates = computed(() => formatEventRange(props.show.dateStart, props.show.dateEnd));
const galleryPath = computed(() => `/gallery/e${props.show.id}`);
const summary = computed(() => plainText(props.show.shortDescription));
</script>

<template>
  <article
    class="grid gap-5 border-b border-border py-6 last:border-b-0 sm:grid-cols-[minmax(0,240px)_1fr]"
    :aria-labelledby="headingId"
  >
    <RouterLink
      :to="show.path"
      class="grid min-h-36 max-w-60 self-start no-underline"
      tabindex="-1"
      aria-hidden="true"
    >
      <SafeImage
        :src="show.imageUrl"
        resize="eventCard"
        alt=""
        :placeholder="show.shortName || show.name"
        width="240"
        height="240"
        img-class="h-auto w-full rounded-sm"
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

      <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[0.8125rem]">
          <template v-if="show.locationLabel">
            <dt class="font-semibold text-heading">
              Location
            </dt>
            <dd>{{ show.locationLabel }}</dd>
          </template>
          <template v-if="show.registrationOpens">
            <dt class="font-semibold text-heading">
              Registration opens
            </dt>
            <dd>{{ formatEventDateTime(show.registrationOpens) }}</dd>
          </template>
          <template v-if="show.registrationCloses">
            <dt class="font-semibold text-heading">
              Closes
            </dt>
            <dd>{{ formatEventDateTime(show.registrationCloses) }}</dd>
          </template>
          <dt class="font-semibold text-heading">
            Event dates
          </dt>
          <dd>{{ dates || 'To be announced' }}</dd>
          <dt class="font-semibold text-heading">
            Registration
          </dt>
          <dd>{{ show.registrationOpen ? 'Open' : 'Closed' }}</dd>
        </dl>

        <ul class="flex flex-wrap gap-2">
          <li>
            <BaseButton
              :to="show.path"
              size="sm"
            >
              Event Info <span class="sr-only">for {{ show.name }}</span>
            </BaseButton>
          </li>
          <li>
            <BaseButton
              :to="galleryPath"
              size="sm"
            >
              Gallery <span class="sr-only">for {{ show.name }}</span>
            </BaseButton>
          </li>
        </ul>
      </div>

      <p
        v-if="summary"
        class="mt-3"
      >
        {{ summary }}
      </p>
    </div>
  </article>
</template>
