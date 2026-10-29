<script setup lang="ts">
/**
 * Event registration (legacy /:event/e:id/register). One page of numbered steps: the form on the left, a live
 * summary beside it on desktop (below the form on mobile). Load, validation, quote and saving live in
 * useRegistrationEditor; this page only lays them out.
 */
import { computed, nextTick, ref } from 'vue';
import { RouterLink, useRouter, useRoute } from 'vue-router';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import ResponseBanner from '../../components/ResponseBanner.vue';
import SubmitButton from '../../components/SubmitButton.vue';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import CancelRegistrationModal from '../../components/registration/CancelRegistrationModal.vue';
import ContactModal from '../../components/contact/ContactModal.vue';
import CarsSection from '../../components/registration/CarsSection.vue';
import GuestsSection from '../../components/registration/GuestsSection.vue';
import ShirtsSection from '../../components/registration/ShirtsSection.vue';
import ExtrasSection from '../../components/registration/ExtrasSection.vue';
import ArrivalSection from '../../components/registration/ArrivalSection.vue';
import DonationSection from '../../components/registration/DonationSection.vue';
import CancellationSection from '../../components/registration/CancellationSection.vue';
import RegistrationSummary from '../../components/registration/RegistrationSummary.vue';
import { useEventInfo } from '../../composables/events/useEventData';
import { provideFormValues } from '../../components/registration/formContext';
import { sectionDomId, useRegistrationEditor } from '../../composables/registration/useRegistrationEditor';
import { useAuthStore } from '../../stores/auth';

const props = defineProps<{ eventName: string; eventId: number; userId?: number }>();

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const info = useEventInfo(() => props.eventId);
const eventInfo = computed(() => info.data.value);


const {
  form,
  loader,
  payload,
  registration,
  ready,
  readOnly,
  hasRegistration,
  restoredDraft,
  discardDraft,
  fieldError,
  touch,
  clientErrors,
  serverErrors,
  saveError,
  saved,
  submitting,
  submitAttempted,
  submit,
  summary,
  quoteState,
  canSetCancelled,
  cancelOpen,
  cancelling,
  cancelError,
  setCancelled,
  reload,
} = useRegistrationEditor({
  showId: props.eventId,
  userId: props.userId,
  sessionUserId: () => auth.userId ?? 0,
  memberEmail: () => auth.user?.email ?? '',
  isAdmin: () => auth.isAdmin,
  registrationOpen: () => eventInfo.value?.registrationOpen ?? false,
  memberPath: () => eventInfo.value?.memberPath ?? '',
  eventDates: () => (eventInfo.value?.showDates ?? []).map((day) => day.date),
  navigate: (path) => router.push(path),
});

provideFormValues(form);

const pageStatus = computed(() => {
  if (info.status.value === 'error' || loader.status.value === 'error') {
    return 'error';
  }
  return info.status.value === 'loading' || loader.status.value === 'loading' ? 'loading' : 'ready';
});

function retry(): void {
  void info.reload();
  void reload();
}

const isCancelled = computed(() => registration.value?.isCancelled ?? false);
const contactOpen = ref(false);
const cancelMode = computed(() => (isCancelled.value ? 'reinstate' : 'cancel'));
const isAdmin = computed(() => auth.isAdmin);
const closedForMember = computed(() => eventInfo.value !== null && !eventInfo.value.registrationOpen && !isAdmin.value);
const targetName = computed(() => {
  const target = payload.value?.targetUser;
  return target === null || target === undefined ? '' : `${target.firstName} ${target.lastName}`.trim() || target.email;
});
const carCount = computed(() => form.cars.length);
const enabledExtras = computed(() => (payload.value?.extras ?? []).filter((extra) => extra.isEnabled));
/** Steps after Extras shift up by one when the event has no extras and that step is hidden. */
const stepAfterExtras = (step: number): number => (enabledExtras.value.length > 0 ? step : step - 1);
const showProblems = computed(() => Object.keys(clientErrors.value).length > 0);
const generalMessages = computed(() => [...(serverErrors.value?.general ?? []), ...(saveError.value ? [saveError.value] : [])]);
const returnTo = computed(() => route.fullPath);
const eventLink = computed(() => ({ name: 'event-info', params: { eventName: props.eventName, eventId: props.eventId } }));
const registrationDates = computed(() => eventInfo.value?.registrationCloseDate ?? '');

/** Inline confirmation for "Discard changes" on the restored-draft banner (no browser confirm()). */
const confirmingDiscard = ref(false);

async function focusById(id: string): Promise<void> {
  await nextTick();
  document.getElementById(id)?.focus();
}

function askDiscard(): void {
  confirmingDiscard.value = true;
  void focusById('draft-discard-keep');
}

function keepDraft(): void {
  confirmingDiscard.value = false;
  void focusById('draft-discard');
}

function confirmDiscard(): void {
  confirmingDiscard.value = false;
  discardDraft();
  void focusById(sectionDomId('cars'));
}
</script>

<template>
  <div class="space-y-6">
    <header class="space-y-2">
      <h1 class="page-title">
        Register
      </h1>
      <p class="text-sm">
        <RouterLink :to="eventLink">
          Back to {{ eventInfo?.name ?? 'the event' }}
        </RouterLink>
      </p>
    </header>

    <AsyncBlock
      :status="pageStatus"
      loading-label="Loading registration"
      error-message="Your registration could not be loaded right now."
      @retry="retry"
    >
      <div
        v-if="ready && eventInfo !== null && payload !== null"
        class="space-y-6"
      >
        <BaseAlert
          v-if="targetName"
          variant="info"
          title="Editing as an administrator"
        >
          <p>You are viewing the registration for {{ targetName }}.</p>
        </BaseAlert>
        <BaseAlert
          v-if="isCancelled && !isAdmin"
          variant="info"
        >
          <p>This registration was cancelled; please contact us.</p>
        </BaseAlert>
        <BaseAlert
          v-else-if="isCancelled"
          variant="info"
        >
          <p>This registration is cancelled. You can reinstate it below.</p>
        </BaseAlert>
        <BaseAlert
          v-else-if="closedForMember"
          variant="info"
        >
          <p>Registration for this event is closed. You can review your details, but changes are no longer accepted.</p>
        </BaseAlert>
        <BaseAlert
          v-if="restoredDraft && !readOnly"
          variant="info"
        >
          <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <p>We restored your unsaved changes from earlier.</p>
            <BaseButton
              v-if="!confirmingDiscard"
              id="draft-discard"
              @click="askDiscard"
            >
              Discard changes
            </BaseButton>
            <div
              v-else
              role="group"
              aria-labelledby="draft-discard-question"
              class="flex flex-wrap items-center gap-2"
            >
              <span
                id="draft-discard-question"
                class="font-medium"
              >Discard unsaved changes?</span>
              <BaseButton
                id="draft-discard-confirm"
                variant="danger"
                @click="confirmDiscard"
              >
                Yes, discard
              </BaseButton>
              <BaseButton
                id="draft-discard-keep"
                @click="keepDraft"
              >
                No, keep them
              </BaseButton>
            </div>
          </div>
        </BaseAlert>
        <BaseAlert
          v-if="saved"
          variant="success"
        >
          <p>Registration saved. Opening your billing page.</p>
        </BaseAlert>
        <BaseAlert
          v-if="submitAttempted && showProblems"
          variant="error"
        >
          <p>Check the highlighted fields before saving.</p>
        </BaseAlert>

        <div class="lg:grid lg:grid-cols-3 lg:items-start lg:gap-6">
          <form
            class="min-w-0 space-y-6 lg:col-span-2"
            novalidate
            :aria-label="`Registration for ${eventInfo.name}`"
            @submit.prevent="submit"
          >
            <ResponseBanner :messages="generalMessages" />
            <fieldset
              :disabled="readOnly"
              class="min-w-0 space-y-6"
            >
              <CarsSection
                :dom-id="sectionDomId('cars')"
                :cars="payload.cars"
                :read-only="readOnly"
                :return-to="returnTo"
                :field-error="fieldError"
                :touch="touch"
                :messages="serverErrors?.cars ?? []"
              />
              <GuestsSection
                :dom-id="sectionDomId('guests')"
                :guest-types="payload.guestTypes"
                :car-count="carCount"
                :read-only="readOnly"
                :field-error="fieldError"
                :touch="touch"
                :server-guests="serverErrors?.guests ?? {}"
                :messages="[]"
              />
              <ShirtsSection
                :dom-id="sectionDomId('shirts')"
                :shirt-types="payload.shirtTypes"
                :registered-shirts="registration?.shirts ?? []"
                :read-only="readOnly"
                :field-error="fieldError"
                :touch="touch"
                :server-shirts="serverErrors?.shirts ?? {}"
                :messages="[]"
              />
              <ExtrasSection
                v-if="enabledExtras.length > 0"
                :dom-id="sectionDomId('extras')"
                :extras="enabledExtras"
                :read-only="readOnly"
                :field-error="fieldError"
                :touch="touch"
                :server-extras="serverErrors?.extras ?? {}"
                :messages="[]"
              />
              <ArrivalSection
                :dom-id="sectionDomId('arrival')"
                :step="stepAfterExtras(5)"
                :event-dates="eventInfo.showDates"
                :read-only="readOnly"
                :field-error="fieldError"
                :touch="touch"
                :messages="serverErrors?.arrival ?? []"
              />
              <DonationSection
                :dom-id="sectionDomId('donation')"
                :step="stepAfterExtras(6)"
                :read-only="readOnly"
                :field-error="fieldError"
                :messages="serverErrors?.donation ?? []"
              />
              <CancellationSection
                :dom-id="sectionDomId('cancel')"
                :step="stepAfterExtras(7)"
                :read-only="readOnly"
                :registration-close-date="registrationDates"
                :error="fieldError('cancel')"
                :touch="touch"
                :messages="[]"
              />
            </fieldset>

            <div
              v-if="!readOnly"
              class="flex flex-wrap items-center justify-between gap-3"
            >
              <SubmitButton
                :busy="submitting"
                busy-label="Saving registration"
              >
                {{ hasRegistration ? 'Update registration' : 'Register' }}
              </SubmitButton>
            </div>
            <p
              v-if="hasRegistration && !isCancelled && !isAdmin"
              class="text-sm"
            >
              Need to cancel? <button
                type="button"
                class="font-medium text-brand underline underline-offset-2"
                @click="contactOpen = true"
              >
                Contact us
              </button>
              and we will update your registration.
            </p>
            <div
              v-if="canSetCancelled"
              class="border-t border-border pt-4"
            >
              <BaseButton
                :variant="isCancelled ? 'default' : 'danger'"
                @click="cancelOpen = true"
              >
                {{ isCancelled ? 'Reinstate registration' : 'Cancel registration' }}
              </BaseButton>
              <span class="ml-2 text-sm text-muted">Admin only</span>
            </div>
          </form>

          <aside class="mt-6 lg:sticky lg:top-4 lg:mt-0">
            <RegistrationSummary
              :summary="summary"
              :status="quoteState.status.value"
            />
          </aside>
        </div>

        <CancelRegistrationModal
          :open="cancelOpen"
          :busy="cancelling"
          :error="cancelError"
          :mode="cancelMode"
          @update:open="cancelOpen = $event"
          @confirm="setCancelled(!isCancelled)"
        />
        <ContactModal v-model:open="contactOpen" />
      </div>
    </AsyncBlock>
  </div>
</template>
