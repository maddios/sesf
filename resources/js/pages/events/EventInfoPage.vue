<script setup lang="ts">
/**
 * Event Info tab: agenda items (type 1) in order. The second item sits beside the sponsor logos, as in the
 * legacy page.
 */
import { computed } from 'vue';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import SafeHtml from '../../components/events/SafeHtml.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import SafeImage from '../../components/ui/SafeImage.vue';
import { fetchAgenda, fetchEventSponsors } from '../../api/events';
import { useEventContext, useEventData } from '../../composables/events/useEventData';
import { formatEventDate } from '../../components/events/format';

const context = useEventContext();
const agenda = useEventData(() => context.eventId.value, (id) => fetchAgenda(id, 1));
const sponsors = useEventData(() => context.eventId.value, fetchEventSponsors);

const items = computed(() => agenda.data.value ?? []);
const sponsorList = computed(() => sponsors.data.value?.sponsors ?? []);
const showDates = computed(() => context.info.value?.showDates ?? []);
const registrationPath = computed(() => (context.info.value?.registrationOpen ? `${context.info.value.path}/register` : null));

function reloadAll(): void {
  void agenda.reload();
  void sponsors.reload();
}
</script>

<template>
  <div class="space-y-8">
    <div
      v-if="showDates.length > 0 || registrationPath"
      class="flex flex-wrap items-start justify-between gap-4"
    >
      <dl
        v-if="showDates.length > 0"
        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm"
      >
        <template
          v-for="row in showDates"
          :key="`${row.name}-${row.date}`"
        >
          <dt class="font-semibold text-title">
            {{ row.name }}
          </dt>
          <dd>{{ formatEventDate(row.date) }}</dd>
        </template>
      </dl>
      <BaseButton
        v-if="registrationPath"
        :to="registrationPath"
        variant="danger"
      >
        Register now
      </BaseButton>
    </div>

    <AsyncBlock
      :status="agenda.status.value"
      loading-label="Loading event details"
      error-message="The event details could not be loaded right now."
      @retry="reloadAll"
    >
      <p
        v-if="items.length === 0"
        class="py-4 text-muted"
      >
        Event details will be posted here soon.
      </p>
      <div
        v-else
        class="space-y-8"
      >
        <template
          v-for="(item, index) in items"
          :key="item.id"
        >
          <section
            v-if="index !== 1 || sponsorList.length === 0"
            :aria-labelledby="`agenda-${item.id}`"
          >
            <h2
              :id="`agenda-${item.id}`"
              class="section-heading"
            >
              {{ item.name }}
            </h2>
            <SafeHtml
              class="mt-4"
              :html="item.text"
            />
          </section>
          <div
            v-else
            class="grid gap-6 md:grid-cols-[minmax(0,1fr)_200px]"
          >
            <section :aria-labelledby="`agenda-${item.id}`">
              <h2
                :id="`agenda-${item.id}`"
                class="section-heading"
              >
                {{ item.name }}
              </h2>
              <SafeHtml
                class="mt-4"
                :html="item.text"
              />
            </section>
            <aside aria-labelledby="event-sponsors-heading">
              <h2
                id="event-sponsors-heading"
                class="text-center text-sm font-semibold uppercase tracking-wide text-muted"
              >
                Sponsors
              </h2>
              <ul class="mt-3 space-y-3">
                <li
                  v-for="sponsor in sponsorList"
                  :key="sponsor.id"
                >
                  <a
                    v-if="sponsor.url"
                    :href="sponsor.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="block no-underline"
                  >
                    <SafeImage
                      :src="sponsor.logoUrl"
                      resize="agendaSponsor"
                      :alt="sponsor.name"
                      :placeholder="sponsor.name"
                      img-class="mx-auto h-14 w-auto object-contain"
                    />
                    <span class="sr-only">{{ sponsor.name }} (opens in a new tab)</span>
                  </a>
                  <SafeImage
                    v-else
                    :src="sponsor.logoUrl"
                    resize="agendaSponsor"
                    :alt="sponsor.name"
                    :placeholder="sponsor.name"
                    img-class="mx-auto h-14 w-auto object-contain"
                  />
                </li>
              </ul>
            </aside>
          </div>
        </template>
      </div>
    </AsyncBlock>
  </div>
</template>
