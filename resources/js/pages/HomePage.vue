<script setup lang="ts">
/**
 * Home: overview video, latest event, additional sponsors, past videos carousel and the PayPal donate block.
 * Same sections as the legacy home, with the event content ahead of its sponsor logos and proper
 * loading / empty / error states for the two data blocks.
 */
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import BaseAlert from '../components/ui/BaseAlert.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import SafeImage from '../components/ui/SafeImage.vue';
import BaseSpinner from '../components/ui/BaseSpinner.vue';
import VideoCarousel, { type CarouselVideo } from '../components/home/VideoCarousel.vue';
import { useHomeEvents, useHomeSponsors } from '../composables/useHomeEvents';

/** 2025 overview video (legacy home embed). */
const HOME_VIDEO_ID = 'zrtLOBS4R9A';
const HOME_VIDEO_TITLE = 'Southeast Sharkfest 2025 overview video';

/** Legacy "SESF Past Videos" carousel, in the legacy order. */
const PAST_VIDEOS: CarouselVideo[] = [
  { id: '42inovnKlto', title: 'SESF past video 1' },
  { id: 'R1DcynIUg7c', title: 'SESF past video 2' },
  { id: 'AtP_5cfMtBI', title: 'SESF past video 3' },
  { id: 'KfeLy75l9mI', title: 'SESF past video 4' },
  { id: 'C_MGUT9n5oM', title: 'SESF past video 5' },
];

const PAYPAL_DONATE_URL = 'https://www.paypal.com/donate';
const PAYPAL_HOSTED_BUTTON_ID = '9LUSEKNJDPB4A';

const events = useHomeEvents(1);
const sponsors = useHomeSponsors(6);

const latestEvent = computed(() => events.data.value?.[0] ?? null);
const sponsorList = computed(() => sponsors.data.value?.sponsors ?? []);
const allSponsorsPath = computed(() => {
  const showId = sponsors.data.value?.showId ?? null;
  return showId === null ? '/events' : `/show/e${showId}/sponsors`;
});

const homeVideoSrc = `https://www.youtube-nocookie.com/embed/${HOME_VIDEO_ID}?rel=0`;
</script>

<template>
  <div class="space-y-10">
    <h1 class="sr-only">
      Welcome to Southeast Sharkfest
    </h1>

    <div class="relative aspect-video w-full overflow-hidden rounded-md bg-heading">
      <iframe
        class="absolute inset-0 h-full w-full"
        :src="homeVideoSrc"
        :title="HOME_VIDEO_TITLE"
        referrerpolicy="strict-origin-when-cross-origin"
        allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen
      />
    </div>

    <div class="grid gap-10 md:grid-cols-3 md:gap-8">
      <!-- Events -->
      <section
        aria-labelledby="home-events-heading"
        class="md:col-span-2"
      >
        <h2
          id="home-events-heading"
          class="section-heading"
        >
          Events
        </h2>

        <BaseSpinner
          v-if="events.status.value === 'loading'"
          label="Loading events"
          class="py-6 text-brand"
        />
        <BaseAlert
          v-else-if="events.status.value === 'error'"
          variant="warning"
        >
          <p>The latest event could not be loaded right now.</p>
          <BaseButton
            variant="link"
            @click="events.reload()"
          >
            Try again
          </BaseButton>
        </BaseAlert>
        <p
          v-else-if="latestEvent === null"
          class="py-4 text-muted"
        >
          No events have been announced yet. Check back soon.
        </p>
        <article
          v-else
          class="grid gap-5 sm:grid-cols-[minmax(0,240px)_1fr]"
          aria-labelledby="home-event-title"
        >
          <RouterLink
            :to="latestEvent.path"
            class="mx-auto grid min-h-36 w-full max-w-60 self-start no-underline"
            tabindex="-1"
            aria-hidden="true"
          >
            <SafeImage
              :src="latestEvent.imageUrl"
              resize="eventCard"
              alt=""
              :placeholder="latestEvent.name"
              width="240"
              height="240"
              img-class="h-auto w-full rounded-sm"
            />
          </RouterLink>
          <div class="min-w-0">
            <h3
              id="home-event-title"
              class="item-title"
            >
              <RouterLink
                :to="latestEvent.path"
                class="text-brand no-underline hover:underline"
              >
                {{ latestEvent.name }}
              </RouterLink>
              {{ ' ' }}<span
                v-if="latestEvent.ended"
                class="ml-2 inline-block rounded-sm bg-subtle px-2 py-0.5 align-middle text-xs font-semibold text-muted"
              >(Ended)</span>
            </h3>
            <p class="mt-3">
              {{ latestEvent.shortDescription }}
            </p>
            <p class="mt-3">
              <RouterLink :to="latestEvent.path">
                More info <span class="sr-only">about {{ latestEvent.name }}</span>
              </RouterLink>
            </p>

            <div
              v-if="latestEvent.primarySponsors.length > 0"
              class="mt-6"
            >
              <h4 class="text-h4 font-semibold uppercase tracking-wide text-muted">
                Sponsored by
              </h4>
              <ul class="mt-2 flex flex-wrap items-center gap-3">
                <li
                  v-for="sponsor in latestEvent.primarySponsors"
                  :key="sponsor.id"
                  class="h-[55px] w-[calc(50%-0.375rem)] max-w-[200px]"
                >
                  <component
                    :is="sponsor.url ? 'a' : 'span'"
                    :href="sponsor.url || undefined"
                    :target="sponsor.url ? '_blank' : undefined"
                    :rel="sponsor.url ? 'noopener noreferrer' : undefined"
                    class="flex h-full items-center justify-center no-underline"
                  >
                    <SafeImage
                      :src="sponsor.logoUrl"
                      resize="homeSponsor"
                      :alt="sponsor.name"
                      img-class="max-h-full max-w-full object-contain"
                    />
                    <span
                      v-if="sponsor.url"
                      class="sr-only"
                    > (opens in a new tab)</span>
                  </component>
                </li>
              </ul>
            </div>
          </div>
        </article>

        <p class="mt-6 border-t border-border pt-4">
          <RouterLink to="/events">
            Show all events
          </RouterLink>
        </p>
      </section>

      <!-- Additional sponsors -->
      <section aria-labelledby="home-sponsors-heading">
        <h2
          id="home-sponsors-heading"
          class="section-heading"
        >
          Additional Sponsors
        </h2>

        <BaseSpinner
          v-if="sponsors.status.value === 'loading'"
          label="Loading sponsors"
          class="py-6 text-brand"
        />
        <BaseAlert
          v-else-if="sponsors.status.value === 'error'"
          variant="warning"
        >
          <p>Sponsors could not be loaded right now.</p>
          <BaseButton
            variant="link"
            @click="sponsors.reload()"
          >
            Try again
          </BaseButton>
        </BaseAlert>
        <p
          v-else-if="sponsorList.length === 0"
          class="py-4 text-muted"
        >
          Sponsors will be announced soon.
        </p>
        <ul
          v-else
          class="flex flex-col items-center gap-3"
        >
          <li
            v-for="sponsor in sponsorList"
            :key="sponsor.id"
            class="h-[65px] w-[250px] max-w-full"
          >
            <component
              :is="sponsor.url ? 'a' : 'span'"
              :href="sponsor.url || undefined"
              :target="sponsor.url ? '_blank' : undefined"
              :rel="sponsor.url ? 'noopener noreferrer' : undefined"
              class="flex h-full items-center justify-center no-underline"
            >
              <SafeImage
                :src="sponsor.logoUrl"
                resize="homeSponsor"
                :alt="sponsor.name"
                img-class="max-h-full max-w-full object-contain"
              />
              <span
                v-if="sponsor.url"
                class="sr-only"
              > (opens in a new tab)</span>
            </component>
          </li>
        </ul>

        <p class="mt-6 border-t border-border pt-4">
          <RouterLink :to="allSponsorsPath">
            See all sponsors
          </RouterLink>
        </p>
      </section>

      <!-- Past videos -->
      <section
        aria-labelledby="home-videos-heading"
        class="md:col-span-2"
      >
        <h2
          id="home-videos-heading"
          class="section-heading"
        >
          SESF Past Videos
        </h2>
        <VideoCarousel
          :videos="PAST_VIDEOS"
          label="Past videos carousel"
        />
      </section>

      <!-- Donate -->
      <section aria-labelledby="home-donate-heading">
        <h2
          id="home-donate-heading"
          class="section-heading"
        >
          Donate
        </h2>
        <p class="font-semibold">
          We do everything we can to keep our overhead and registration costs as low as possible every year.
          If you have been to a SESF event, hopefully you can attest to the value of our program.
          The donations we receive every year provide us with additional support that goes a LONG way.
          If you'd like to further support our cause, we tremendously appreciate your donations!
        </p>
        <form
          :action="PAYPAL_DONATE_URL"
          method="post"
          target="_blank"
          rel="noopener noreferrer"
          class="mt-6 flex justify-center"
        >
          <input
            type="hidden"
            name="hosted_button_id"
            :value="PAYPAL_HOSTED_BUTTON_ID"
          >
          <!-- A styled button instead of PayPal's remote GIF: no third-party image, no alt text when it fails. -->
          <button
            type="submit"
            class="inline-flex flex-col items-center rounded-full border border-[#e0a800] bg-[#ffc439] px-8 py-2 font-semibold text-[#1f2933] shadow-card hover:bg-[#f2b600]"
          >
            <span class="text-base">Donate</span>
            <span class="text-xs font-medium">with PayPal</span>
            <span class="sr-only">(opens in a new tab)</span>
          </button>
        </form>
      </section>
    </div>
  </div>
</template>
