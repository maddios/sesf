import { render } from '@testing-library/vue';
import type { Component } from 'vue';
import { createTestRouter, SESSION_PAYLOAD } from '../../../test/helpers';
import { toAuthUser, useAuthStore, USER_TYPE_ADMIN } from '../../../stores/auth';

/** The signed-in member used by account tests: id 7, regular level. */
export const OWNER_ID = 7;

interface MountOptions {
  props?: Record<string, unknown>;
  /** Signed-in member id, or null for an anonymous visitor. */
  userId?: number | null;
  admin?: boolean;
  path?: string;
}

/** Renders an account component with a fresh Pinia store, a memory router and (optionally) a signed-in user. */
export async function mountAccount(component: Component, options: MountOptions = {}) {
  const { router, pinia } = createTestRouter();
  const auth = useAuthStore(pinia);
  const userId = options.userId === undefined ? OWNER_ID : options.userId;
  if (userId !== null) {
    const user = toAuthUser({ ...SESSION_PAYLOAD, user_id: userId, user_level: options.admin ? USER_TYPE_ADMIN : 1 });
    auth.setUser(user);
  }
  await router.push(options.path ?? '/');
  await router.isReady();
  const utils = render(component, { props: options.props, global: { plugins: [pinia, router] } });
  return { router, pinia, auth, ...utils };
}
