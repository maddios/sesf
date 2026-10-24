import { describe, it, expect, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useAuthStore, type AuthUser } from '../auth';

const sampleUser: AuthUser = { id: 1, name: 'Shark Fan', email: 'fan@example.test', level: 3 };

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('starts logged out and unresolved', () => {
    const store = useAuthStore();
    expect(store.user).toBeNull();
    expect(store.resolved).toBe(false);
    expect(store.isLoggedIn).toBe(false);
    expect(store.userLevel).toBe(0);
  });

  it('setUser stores the user, marks resolved, and derives login state and level', () => {
    const store = useAuthStore();
    store.setUser(sampleUser);
    expect(store.user).toEqual(sampleUser);
    expect(store.resolved).toBe(true);
    expect(store.isLoggedIn).toBe(true);
    expect(store.userLevel).toBe(3);
  });

  it('clear logs out but keeps the session resolved', () => {
    const store = useAuthStore();
    store.setUser(sampleUser);
    store.clear();
    expect(store.user).toBeNull();
    expect(store.isLoggedIn).toBe(false);
    expect(store.userLevel).toBe(0);
    expect(store.resolved).toBe(true);
  });
});
