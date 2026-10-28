<script setup lang="ts">
/**
 * Member profile (legacy /:name/u:id). Public view for everyone; the profile owner and admins also get the
 * private tabs (Profile, Garage, Settings, Billing). Only the fields the API returns are shown.
 */
import { computed, ref, shallowRef, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { ApiError } from '../../api/client';
import { GENERIC_AVATAR_URL, fetchMemberProfile, type MemberProfile } from '../../api/members';
import type { CarSummary } from '../../api/cars';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseCard from '../../components/ui/BaseCard.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';
import SafeImage from '../../components/ui/SafeImage.vue';
import MemberContactModal from '../../components/member/MemberContactModal.vue';
import MemberTabs from '../../components/member/MemberTabs.vue';
import { useAuthStore } from '../../stores/auth';
import { setPageTitle } from '../../composables/usePageTitle';

const props = defineProps<{ memberName: string; memberId: number }>();

type Status = 'loading' | 'ready' | 'notFound' | 'error';

const auth = useAuthStore();
const profile = shallowRef<MemberProfile | null>(null);
const status = ref<Status>('loading');
const contactOpen = ref(false);
const imageFailed = ref(false);
let run = 0;

async function load(): Promise<void> {
  run += 1;
  const current = run;
  status.value = 'loading';
  try {
    const next = await fetchMemberProfile(props.memberId);
    if (current !== run) {
      return;
    }
    profile.value = next;
    status.value = 'ready';
    imageFailed.value = false;
    setPageTitle(next.fullName || props.memberName);
  } catch (failure) {
    if (current !== run) {
      return;
    }
    profile.value = null;
    const notFound = failure instanceof ApiError && failure.status === 404;
    status.value = notFound ? 'notFound' : 'error';
    setPageTitle(notFound ? 'Member not found' : 'Member');
  }
}

watch(() => props.memberId, () => void load(), { immediate: true });

const imageSrc = computed(() => {
  const p = profile.value;
  return p === null || imageFailed.value || !p.hasImage || p.imageUrl === '' ? GENERIC_AVATAR_URL : p.imageUrl;
});

function onImageError(): void {
  imageFailed.value = true;
}

const canShowTabs = computed(() => profile.value !== null && (profile.value.isOwner || auth.isAdmin));
const canContact = computed(() => profile.value !== null && auth.isLoggedIn && profile.value.allowPrivateMessage && !profile.value.isOwner);
const garage = computed<CarSummary[]>(() => profile.value?.cars.slice(0, 3) ?? []);
const profileBase = computed(() => `/${encodeURIComponent(props.memberName)}/u${props.memberId}`);

function carTitle(car: CarSummary): string {
  return car.nickname || [car.year, car.model].filter(Boolean).join(' ') || `Car ${car.id}`;
}
</script>

<template>
  <section class="space-y-6">
    <BaseSpinner
      v-if="status === 'loading'"
      label="Loading member profile"
      class="py-6 text-brand"
    />

    <div
      v-else-if="status === 'notFound'"
      class="mx-auto max-w-xl"
    >
      <h1 class="page-title">
        Member not found
      </h1>
      <p class="mt-3">
        This member profile does not exist or is not available.
      </p>
      <p class="mt-4">
        <RouterLink to="/members">
          Browse all members
        </RouterLink>
      </p>
    </div>

    <BaseAlert
      v-else-if="status === 'error'"
      variant="error"
    >
      <p>The member profile could not be loaded.</p>
      <BaseButton
        variant="link"
        @click="load()"
      >
        Try again
      </BaseButton>
    </BaseAlert>

    <template v-else-if="profile !== null">
      <MemberTabs
        v-if="canShowTabs"
        :member-name="memberName"
        :member-id="memberId"
      />

      <header class="flex flex-col gap-5 sm:flex-row sm:items-start">
        <SafeImage
          :src="imageSrc"
          resize="memberPhoto"
          :alt="`Photo of ${profile.fullName}`"
          img-class="h-40 w-40 rounded-md border border-border object-cover"
          width="160"
          height="160"
          loading="eager"
          @error="onImageError"
        />
        <div class="min-w-0 flex-1 space-y-2">
          <h1 class="page-title">
            {{ profile.fullName || memberName }}
          </h1>
          <p
            v-if="profile.cityState"
            class="text-muted"
          >
            {{ profile.cityState }}
          </p>
          <div
            v-if="canContact"
            class="pt-2"
          >
            <BaseButton
              variant="primary"
              @click="contactOpen = true"
            >
              Contact {{ profile.firstNameLastInitial || profile.fullName }}
            </BaseButton>
          </div>
        </div>
      </header>

      <div class="grid gap-6 lg:grid-cols-2">
        <BaseCard title="Garage">
          <p
            v-if="profile.cars.length === 0"
            class="text-muted"
          >
            No cars listed.
          </p>
          <template v-else>
            <ul class="grid grid-cols-3 gap-3">
              <li
                v-for="car in garage"
                :key="car.id"
              >
                <RouterLink
                  :to="`/car/${car.id}`"
                  class="block no-underline"
                >
                  <!-- The visible title below is the link text, so the photo is decorative. -->
                  <SafeImage
                    :src="car.imageUrl"
                    resize="memberCar"
                    alt=""
                    img-class="aspect-[4/3] w-full rounded-sm border border-border object-cover"
                  />
                  <span class="mt-1 block text-sm text-text hover:underline">{{ carTitle(car) }}</span>
                </RouterLink>
              </li>
            </ul>
            <p
              v-if="profile.isOwner && profile.carCount > 0"
              class="mt-4"
            >
              <RouterLink :to="`${profileBase}/cars`">
                See all {{ profile.carCount }} {{ profile.carCount === 1 ? 'car' : 'cars' }}
              </RouterLink>
            </p>
          </template>
        </BaseCard>

        <BaseCard :title="`Attended shows (${profile.eventCount})`">
          <p
            v-if="profile.events.length === 0"
            class="text-muted"
          >
            No attended shows yet.
          </p>
          <ul
            v-else
            class="space-y-4"
          >
            <li
              v-for="event in profile.events"
              :key="event.id"
            >
              <h3 class="item-title">
                <RouterLink
                  v-if="event.profilePath"
                  :to="event.profilePath"
                >
                  {{ event.showName }}
                </RouterLink>
                <template v-else>
                  {{ event.showName }}
                </template>
              </h3>
              <p
                v-if="event.shortDate"
                class="text-sm text-muted"
              >
                {{ event.shortDate }}
              </p>
              <ul
                v-if="event.cars.length > 0"
                class="mt-2 list-inside list-disc text-sm"
              >
                <li
                  v-for="car in event.cars"
                  :key="car.id"
                >
                  <RouterLink :to="`/car/${car.id}`">
                    {{ carTitle(car) }}
                  </RouterLink>
                </li>
              </ul>
            </li>
          </ul>
        </BaseCard>
      </div>

      <MemberContactModal
        v-if="canContact"
        v-model:open="contactOpen"
        :recipient-id="memberId"
        :recipient-name="profile.fullName || memberName"
      />
    </template>
  </section>
</template>
