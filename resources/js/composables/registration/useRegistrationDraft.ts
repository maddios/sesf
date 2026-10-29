import { ref, watch } from 'vue';
import { defineStore } from 'pinia';
import type { FormValues } from './form';
import { useAuthStore } from '../../stores/auth';

/**
 * In-progress registration, keyed by the signed-in member and show (plus the member being edited when an admin acts
 * for someone else). Kept in memory only, not localStorage, so it survives client-side navigation (e.g. a trip to
 * /car/new) but not a page reload.
 */
export function draftKey(showId: number, sessionUserId: number, targetUserId?: number): string {
  const base = `user:${sessionUserId}:show:${showId}`;
  return targetUserId === undefined ? base : `${base}:member:${targetUserId}`;
}

export const useRegistrationDraftStore = defineStore('registrationDraft', () => {
  const drafts = ref<Record<string, FormValues>>({});

  // Drafts never outlive a session: signing out or in (as anyone) empties the store, so the next person to use this
  // tab cannot see the previous person's guest names or emails. Subscribed here, so the auth store is not edited.
  const auth = useAuthStore();
  watch(
    () => auth.userId,
    () => {
      drafts.value = {};
    },
    { flush: 'sync' },
  );

  function get(key: string): FormValues | undefined {
    return drafts.value[key];
  }

  function set(key: string, values: FormValues): void {
    drafts.value[key] = JSON.parse(JSON.stringify(values)) as FormValues;
  }

  function clear(key: string): void {
    delete drafts.value[key];
  }

  return { get, set, clear, clearAll: () => (drafts.value = {}) };
});
