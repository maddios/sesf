import { ref } from 'vue';
import { defineStore } from 'pinia';
import type { FormValues } from './form';

/**
 * In-progress registration per show (and per member when an admin edits someone else's). Kept in memory only, not
 * localStorage, so it survives client-side navigation (e.g. a trip to /car/new) but not a page reload.
 */
export function draftKey(showId: number, userId?: number): string {
  return userId === undefined ? `show:${showId}` : `show:${showId}:user:${userId}`;
}

export const useRegistrationDraftStore = defineStore('registrationDraft', () => {
  const drafts = ref<Record<string, FormValues>>({});

  function get(key: string): FormValues | undefined {
    return drafts.value[key];
  }

  function set(key: string, values: FormValues): void {
    drafts.value[key] = JSON.parse(JSON.stringify(values)) as FormValues;
  }

  function clear(key: string): void {
    delete drafts.value[key];
  }

  return { get, set, clear };
});
