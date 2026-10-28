<script setup lang="ts">
/**
 * Garage (legacy /:name/u:id/cars): the member's cars that have not been sold. Each car links to its settings,
 * can be marked as sold after a confirm, and the page offers adding a car.
 */
import { computed, onMounted, ref, shallowRef } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { RouterLink } from 'vue-router';
import { fetchGarage, sellCar, messagesFromError } from '../../api/account';
import type { CarSummary } from '../../api/cars';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseModal from '../../components/ui/BaseModal.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';
import SafeImage from '../../components/ui/SafeImage.vue';
import MemberTabs from '../../components/member/MemberTabs.vue';

const props = defineProps<{ memberName: string; memberId: number }>();

const auth = useAuthStore();
/** Edits (add, edit, sell) act on the signed-in member only; other members' garages are read-only here. */
const isOwner = computed(() => auth.userId === props.memberId);

const CAR_FALLBACK_IMAGE = '/images/car-generic.jpg';

type Status = 'loading' | 'ready' | 'error';

const status = ref<Status>('loading');
const cars = shallowRef<CarSummary[]>([]);
const sellTarget = ref<CarSummary | null>(null);
const sellError = ref<string | null>(null);
const selling = ref(false);

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    cars.value = await fetchGarage(props.memberId);
    status.value = 'ready';
  } catch {
    status.value = 'error';
  }
}

function carTitle(car: CarSummary): string {
  return car.nickname || [car.year, car.model].filter(Boolean).join(' ') || car.name || `Car ${car.id}`;
}

function openSell(car: CarSummary): void {
  sellError.value = null;
  sellTarget.value = car;
}

function onSellOpenChange(value: boolean): void {
  if (!value) {
    sellTarget.value = null;
  }
}

async function confirmSell(): Promise<void> {
  const target = sellTarget.value;
  if (target === null) {
    return;
  }
  selling.value = true;
  sellError.value = null;
  try {
    await sellCar(target.id);
    cars.value = cars.value.filter((car) => car.id !== target.id);
    sellTarget.value = null;
  } catch (error) {
    const messages = messagesFromError(error);
    sellError.value = messages[0] ?? 'The car could not be marked as sold. Please try again.';
  } finally {
    selling.value = false;
  }
}

const count = computed(() => cars.value.length);

onMounted(() => void load());
</script>

<template>
  <section class="space-y-6">
    <MemberTabs
      :member-name="memberName"
      :member-id="memberId"
    />
    <header class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="page-title">
        Garage
      </h1>
      <BaseButton
        v-if="isOwner"
        variant="primary"
        to="/car/new"
      >
        Add car
      </BaseButton>
    </header>

    <BaseSpinner
      v-if="status === 'loading'"
      label="Loading garage"
      class="py-6 text-brand"
    />
    <BaseAlert
      v-else-if="status === 'error'"
      variant="error"
    >
      <p>Your garage could not be loaded.</p>
      <BaseButton
        variant="link"
        @click="load()"
      >
        Try again
      </BaseButton>
    </BaseAlert>
    <p
      v-else-if="count === 0"
      class="text-muted"
    >
      No cars in the garage yet.
    </p>
    <ul
      v-else
      class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
      aria-label="Cars in the garage"
    >
      <li
        v-for="car in cars"
        :key="car.id"
        class="flex flex-col overflow-hidden rounded-md border border-border bg-panel shadow-card"
      >
        <SafeImage
          :src="car.imageUrl || CAR_FALLBACK_IMAGE"
          resize="garageCar"
          :alt="`Photo of ${carTitle(car)}`"
          img-class="aspect-[4/3] w-full object-cover"
        />
        <div class="flex flex-1 flex-col gap-3 p-4">
          <h2 class="text-base font-semibold text-heading">
            <RouterLink
              v-if="car.profilePath"
              :to="car.profilePath"
            >
              {{ carTitle(car) }}
            </RouterLink>
            <template v-else>
              {{ carTitle(car) }}
            </template>
          </h2>
          <p
            v-if="car.nickname && (car.year || car.model)"
            class="text-sm text-muted"
          >
            {{ [car.year, car.model].filter(Boolean).join(' ') }}
          </p>
          <div
            v-if="isOwner"
            class="mt-auto flex flex-wrap gap-2"
          >
            <BaseButton
              size="sm"
              :to="`/car/${car.id}/settings`"
            >
              Edit<span class="sr-only"> {{ carTitle(car) }}</span>
            </BaseButton>
            <BaseButton
              size="sm"
              @click="openSell(car)"
            >
              Mark as sold<span class="sr-only"> {{ carTitle(car) }}</span>
            </BaseButton>
          </div>
        </div>
      </li>
    </ul>

    <BaseModal
      :open="sellTarget !== null"
      title="Mark this car as sold?"
      size="sm"
      @update:open="onSellOpenChange"
    >
      <p class="text-sm">
        <strong>{{ sellTarget ? carTitle(sellTarget) : '' }}</strong> will be removed from your garage. This
        cannot be undone from this page.
      </p>
      <BaseAlert
        v-if="sellError"
        variant="error"
        class="mt-3"
      >
        {{ sellError }}
      </BaseAlert>
      <template #footer>
        <BaseButton
          :disabled="selling"
          @click="onSellOpenChange(false)"
        >
          Keep car
        </BaseButton>
        <BaseButton
          variant="danger"
          :loading="selling"
          loading-label="Marking as sold"
          @click="confirmSell"
        >
          Mark as sold
        </BaseButton>
      </template>
    </BaseModal>
  </section>
</template>
