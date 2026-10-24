import { computed, ref } from 'vue';
import { defineStore } from 'pinia';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  /** Placeholder permission level; the real level model is TBD. */
  level: number;
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null);
  /** True once the initial session check has completed (logged in or not). */
  const resolved = ref(false);

  const isLoggedIn = computed(() => user.value !== null);
  const userLevel = computed(() => user.value?.level ?? 0);

  function setUser(next: AuthUser) {
    user.value = next;
    resolved.value = true;
  }

  function clear() {
    user.value = null;
    resolved.value = true;
  }

  return { user, resolved, isLoggedIn, userLevel, setUser, clear };
});
