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
import CarThumbnail from '../../components/member/CarThumbnail.vue';
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

      <div class="grid gap-8 md:grid-cols-[minmax(0,228px)_minmax(0,1fr)]">
        <aside class="space-y-3 self-start border-b border-border pb-4">
          <h1 class="section-heading">
            {{ profile.fullName || memberName }}
          </h1>
          <SafeImage
            :src="imageSrc"
            resize="memberPhoto"
            :alt="`Photo of ${profile.fullName}`"
            img-class="mx-auto h-auto w-full max-w-[228px] rounded-md"
            width="228"
            height="228"
            loading="eager"
            @error="onImageError"
          />
          <p
            v-if="profile.cityState"
            class="text-muted"
          >
            {{ profile.cityState }}
          </p>
          <div
            v-if="canContact"
            class="border-t border-border pt-3"
          >
            <BaseButton
              variant="primary"
              size="sm"
              @click="contactOpen = true"
            >
              Contact {{ profile.firstNameLastInitial || profile.fullName }}
            </BaseButton>
          </div>
        </aside>

        <div class="min-w-0 space-y-8">
          <section aria-labelledby="member-garage-heading">
            <h2
              id="member-garage-heading"
              class="section-heading"
            >
              Garage
            </h2>
            <p
              v-if="profile.cars.length === 0"
              class="text-muted"
            >
              This member has not added any cars to their garage yet.
            </p>
            <template v-else>
              <ul class="grid grid-cols-3 gap-2 sm:gap-4">
                <li
                  v-for="car in garage"
                  :key="car.id"
                >
                  <CarThumbnail :car="car" />
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
          </section>

          <section
            v-if="profile.events.length > 0 || profile.isOwner"
            aria-labelledby="member-events-heading"
          >
            <h2
              id="member-events-heading"
              class="section-heading"
            >
              Attended Events
            </h2>
            <p
              v-if="profile.events.length === 0"
              class="text-muted"
            >
              You have not attended any events yet.
            </p>
            <ul
              v-else
              class="space-y-3"
            >
              <li
                v-for="event in profile.events"
                :key="event.id"
                class="overflow-hidden rounded-md border border-border"
              >
                <h3 class="flex flex-wrap items-baseline gap-x-3 border-b border-border bg-subtle px-3 py-2.5 text-[0.8125rem] font-bold text-heading">
                  <RouterLink
                    v-if="event.profilePath"
                    :to="event.profilePath"
                    class="text-heading no-underline hover:text-brand hover:underline"
                  >
                    {{ event.showName }}
                  </RouterLink>
                  <template v-else>
                    {{ event.showName }}
                  </template>
                  <span
                    v-if="event.shortDate"
                    class="text-xs font-normal text-muted"
                  >{{ event.shortDate }}</span>
                </h3>
                <div class="flex gap-4 p-3">
                  <RouterLink
                    v-if="event.show?.imageUrl && event.profilePath"
                    :to="event.profilePath"
                    class="block w-[88px] shrink-0 self-start no-underline sm:w-[100px]"
                    tabindex="-1"
                    aria-hidden="true"
                  >
                    <SafeImage
                      :src="event.show.imageUrl"
                      resize="attendedShow"
                      alt=""
                      img-class="h-auto w-full rounded-sm"
                    />
                  </RouterLink>
                  <div
                    v-if="event.cars.length > 0"
                    class="min-w-0 flex-1"
                  >
                    <h4 class="text-[0.625rem] font-semibold uppercase tracking-wide text-muted">
                      Cars
                    </h4>
                    <ul class="mt-1 grid grid-cols-2 gap-3 sm:grid-cols-3">
                      <li
                        v-for="car in event.cars"
                        :key="car.id"
                      >
                        <CarThumbnail :car="car" />
                      </li>
                    </ul>
                  </div>
                </div>
              </li>
            </ul>
          </section>
        </div>
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
