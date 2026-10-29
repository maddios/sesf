import { computed, nextTick, reactive, ref, shallowRef, watch } from 'vue';
import { ApiError } from '../../api/client';
import { useAsyncData } from '../useAsyncData';
import {
  fetchQuote,
  fetchRegistration,
  hasRegistrationErrors,
  parseRegistrationErrors,
  saveRegistration,
  type Payment,
  type Quote,
  type RegistrationData,
  type RegistrationErrors,
} from '../../api/registration';
import { buildRequest, cloneForm, emptyForm, enabledExtras, formFromRegistration, restoreForm, type FormValues } from './form';
import { draftKey, useRegistrationDraftStore } from './useRegistrationDraft';
import { useRegistrationQuote } from './useRegistrationQuote';
import { validateCancelAck, validateRegistration, type FieldErrors } from './validation';
import { messagesFromError } from '../../api/account';

/** Sections in page order. Focus moves to the first one that has a problem. */
export const SECTION_ORDER = ['cars', 'guests', 'shirts', 'extras', 'arrival', 'donation', 'cancel'] as const;
export type SectionId = (typeof SECTION_ORDER)[number];

/** DOM id of a section's wrapper; used to move focus after a failed submit. */
export function sectionDomId(section: SectionId): string {
  return `registration-section-${section}`;
}

/** Which section a field path belongs to ("guests.2.first" -> guests). */
export function sectionOf(path: string): SectionId {
  const head = path.split('.')[0] ?? '';
  if (head === 'arrival') {
    return 'arrival';
  }
  return (SECTION_ORDER as readonly string[]).includes(head) ? (head as SectionId) : 'cars';
}

function firstSection(paths: string[]): SectionId | null {
  const sections = new Set(paths.map(sectionOf));
  return SECTION_ORDER.find((section) => sections.has(section)) ?? null;
}

function firstServerSection(errors: RegistrationErrors): SectionId | null {
  const present: SectionId[] = [];
  if (errors.cars.length > 0) present.push('cars');
  if (Object.keys(errors.guests).length > 0) present.push('guests');
  if (Object.keys(errors.shirts).length > 0) present.push('shirts');
  if (Object.keys(errors.extras).length > 0) present.push('extras');
  if (errors.arrival.length > 0) present.push('arrival');
  if (errors.donation.length > 0) present.push('donation');
  return SECTION_ORDER.find((section) => present.includes(section)) ?? null;
}

export interface EditorOptions {
  showId: number;
  /** Set by an admin acting for a member (?user_id=). */
  userId?: number;
  /** The signed-in member (the draft owner), not the member being edited. */
  sessionUserId: () => number;
  memberEmail: () => string;
  isAdmin: () => boolean;
  registrationOpen: () => boolean;
  /** Hash path of the member's area for this event, e.g. /Shark%20F/u7. */
  memberPath: () => string;
  eventDates: () => string[];
  navigate: (path: string) => Promise<unknown>;
}

/**
 * State and actions for the registration form: load, draft restore, inline validation, quote, save, cancel. The
 * page renders this; server messages are kept apart from client ones so each can be shown where it belongs.
 */
export function useRegistrationEditor(options: EditorOptions) {
  const drafts = useRegistrationDraftStore();
  const key = draftKey(options.showId, options.sessionUserId(), options.userId);
  const loader = useAsyncData(() => fetchRegistration(options.showId, options.userId));

  const form = reactive<FormValues>(emptyForm()) as FormValues;
  const ready = ref(false);
  const restoredDraft = ref(false);
  const touched = ref<Record<string, boolean>>({});
  const submitAttempted = ref(false);
  const serverErrors = shallowRef<RegistrationErrors | null>(null);
  const saveError = ref<string | null>(null);
  const saved = ref(false);
  const submitting = ref(false);
  const focusSection = ref<SectionId | null>(null);
  const cancelOpen = ref(false);
  const cancelling = ref(false);
  const cancelError = ref<string | null>(null);

  const payload = computed<RegistrationData | null>(() => loader.data.value);
  const registration = computed(() => payload.value?.registration ?? null);
  const readOnly = computed(() => {
    if (options.isAdmin()) {
      return false;
    }
    return !options.registrationOpen() || (registration.value?.isCancelled ?? false);
  });
  const hasRegistration = computed(() => (registration.value?.id ?? null) !== null);
  /** Only an admin acting for a member (?user_id=) can cancel or reinstate; members contact the organizers. */
  const canSetCancelled = computed(() => options.isAdmin() && options.userId !== undefined && hasRegistration.value);

  /** Client checks of the form itself (everything the server checks on save and quote). */
  const formErrors = computed<FieldErrors>(() => {
    const data = payload.value;
    if (data === null) {
      return {};
    }
    return validateRegistration(form, {
      memberEmail: options.memberEmail(),
      cars: data.cars,
      extras: enabledExtras(data.extras),
      eventDates: options.eventDates(),
    });
  });
  const clientErrors = computed<FieldErrors>(() =>
    payload.value === null ? {} : { ...formErrors.value, ...validateCancelAck(form) },
  );

  /** Client message for a field, shown once the field was left or a submit was attempted. */
  function fieldError(path: string): string | undefined {
    if (!submitAttempted.value && touched.value[path] !== true) {
      return undefined;
    }
    return clientErrors.value[path];
  }

  function touch(path: string): void {
    touched.value[path] = true;
  }

  function initialize(data: RegistrationData, fromDraft: boolean): void {
    ready.value = false;
    const stored = fromDraft ? drafts.get(key) : undefined;
    if (stored !== undefined) {
      Object.assign(form, restoreForm(stored, data.cars.map((car) => car.id)));
      restoredDraft.value = true;
    } else {
      Object.assign(form, formFromRegistration(data));
      restoredDraft.value = false;
    }
    ready.value = true;
  }

  // Sync flush: the form is replaced in one step and the draft mirrors every edit without a stale write in between.
  watch(
    () => loader.data.value,
    (data) => {
      if (data !== null) {
        initialize(data, true);
      }
    },
    { flush: 'sync' },
  );
  watch(
    form,
    (values) => {
      if (ready.value) {
        drafts.set(key, cloneForm(values));
      }
    },
    { deep: true, flush: 'sync' },
  );

  // The quote endpoint applies the same checks as a save (at least one car, arrival day and time, ...), so a
  // quote is only requested once the client-side checks pass. Otherwise every incomplete form got a 400 and the
  // summary showed "Totals could not be updated" (found by E2E, docs/e2e.md). The cancellation acknowledgement
  // is not part of the quote.
  const requestBody = computed(() => {
    if (!ready.value || payload.value === null) {
      return null;
    }
    // The server also needs at least one car; the form leaves that message to the server on submit.
    return form.cars.length > 0 && Object.keys(formErrors.value).length === 0 ? buildRequest(form) : null;
  });
  const quoteState = useRegistrationQuote(requestBody, (body) => fetchQuote(options.showId, body, options.userId));

  /** Totals to show: the live quote once it has arrived, else the saved payment. */
  const summary = computed<Payment | Quote | null>(() => quoteState.quote.value ?? payload.value?.payment ?? null);

  function applySaved(data: RegistrationData): void {
    drafts.clear(key);
    loader.data.value = data;
    serverErrors.value = null;
    saveError.value = null;
    submitAttempted.value = false;
    touched.value = {};
  }

  /**
   * Drops the restored draft: the form goes back to the saved registration (blank when there is none), the
   * messages are cleared and the totals are asked for again.
   */
  function discardDraft(): void {
    const data = payload.value;
    if (data === null) {
      return;
    }
    drafts.clear(key);
    initialize(data, false);
    serverErrors.value = null;
    saveError.value = null;
    submitAttempted.value = false;
    touched.value = {};
    quoteState.refresh();
  }

  async function submit(): Promise<void> {
    if (readOnly.value || submitting.value) {
      return;
    }
    submitAttempted.value = true;
    saved.value = false;
    serverErrors.value = null;
    saveError.value = null;
    const problem = firstSection(Object.keys(clientErrors.value));
    if (problem !== null) {
      focusSection.value = problem;
      return;
    }
    submitting.value = true;
    try {
      const data = await saveRegistration(options.showId, buildRequest(form), options.userId);
      applySaved(data);
      saved.value = true;
      const id = data.registration.id;
      const base = options.memberPath();
      if (id !== null && base !== '') {
        await options.navigate(`${base}/billing/${id}`);
      }
    } catch (error) {
      const parsed = error instanceof ApiError && error.status === 400 ? parseRegistrationErrors(error.body) : null;
      if (parsed !== null && hasRegistrationErrors(parsed)) {
        serverErrors.value = parsed;
        focusSection.value = firstServerSection(parsed) ?? 'cars';
      } else {
        saveError.value = messagesFromError(error)[0] ?? 'Your registration could not be saved. Please try again.';
      }
    } finally {
      submitting.value = false;
    }
  }

  /** Admin only: cancels (true) or reinstates (false) the registration, from its saved state. */
  async function setCancelled(cancel: boolean): Promise<void> {
    const current = payload.value;
    if (current === null || cancelling.value || !canSetCancelled.value) {
      return;
    }
    cancelling.value = true;
    cancelError.value = null;
    try {
      const data = await saveRegistration(options.showId, buildRequest(formFromRegistration(current), cancel), options.userId);
      applySaved(data);
      cancelOpen.value = false;
    } catch (error) {
      cancelError.value = messagesFromError(error)[0] ?? 'The registration could not be updated. Please try again.';
    } finally {
      cancelling.value = false;
    }
  }

  /** Moves focus to a section after a failed submit and once the DOM has updated. */
  watch(focusSection, async (section) => {
    if (section === null) {
      return;
    }
    await nextTick();
    document.getElementById(sectionDomId(section))?.focus();
    focusSection.value = null;
  });

  return {
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
    reload: () => loader.reload(),
  };
}

export type RegistrationEditor = ReturnType<typeof useRegistrationEditor>;
