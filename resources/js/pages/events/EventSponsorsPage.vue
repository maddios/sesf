<script setup lang="ts">
/** Sponsors tab: sponsors grouped by tier (M, Gold, Silver, Bronze) in legacy order. */
import { computed } from 'vue';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import { groupSponsorsByTier } from '../../components/events/sponsorTiers';
import SafeImage from '../../components/ui/SafeImage.vue';
import { fetchEventSponsors } from '../../api/events';
import { useEventContext, useEventData } from '../../composables/events/useEventData';

const context = useEventContext();
const sponsors = useEventData(() => context.eventId.value, fetchEventSponsors);
const tiers = computed(() => groupSponsorsByTier(sponsors.data.value?.sponsors ?? []));
</script>

<template>
  <AsyncBlock
    :status="sponsors.status.value"
    loading-label="Loading sponsors"
    error-message="The sponsors could not be loaded right now."
    @retry="sponsors.reload()"
  >
    <p
      v-if="tiers.length === 0"
      class="py-4 text-muted"
    >
      Sponsors for this event will be announced soon.
    </p>
    <div
      v-else
      class="space-y-10"
    >
      <section
        v-for="tier in tiers"
        :key="tier.key"
        :aria-labelledby="`${tier.key}-heading`"
      >
        <h2
          :id="`${tier.key}-heading`"
          class="section-heading"
        >
          {{ tier.heading }}
        </h2>
        <ul class="mt-6 space-y-6">
          <li
            v-for="sponsor in tier.sponsors"
            :key="sponsor.id"
            class="flex flex-col gap-4 sm:flex-row sm:items-start"
          >
            <div class="w-full shrink-0 sm:w-48">
              <a
                v-if="sponsor.url"
                :href="sponsor.url"
                target="_blank"
                rel="noopener noreferrer"
                class="block no-underline"
              >
                <SafeImage
                  :src="sponsor.logoUrl"
                  alt=""
                  :placeholder="sponsor.name"
                  img-class="h-16 w-full object-contain"
                />
                <span class="sr-only">{{ sponsor.name }} (opens in a new tab)</span>
              </a>
              <SafeImage
                v-else
                :src="sponsor.logoUrl"
                alt=""
                :placeholder="sponsor.name"
                img-class="h-16 w-full object-contain"
              />
            </div>
            <div class="min-w-0 flex-1">
              <h3 class="text-base font-semibold text-heading">
                {{ sponsor.name }}
              </h3>
              <p
                v-if="sponsor.description"
                class="mt-1"
              >
                {{ sponsor.description }}
              </p>
              <a
                v-if="sponsor.url"
                :href="sponsor.url"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-2 inline-block text-sm"
              >Visit sponsor website <span class="sr-only">for {{ sponsor.name }} (opens in a new tab)</span></a>
            </div>
          </li>
        </ul>
      </section>
    </div>
  </AsyncBlock>
</template>
