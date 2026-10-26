import { describe, it, expect, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { draftKey, useRegistrationDraftStore } from '../useRegistrationDraft';
import { emptyForm } from '../form';
import { useAuthStore, USER_TYPE_REGULAR, type AuthUser } from '../../../stores/auth';

const member = (userId: number): AuthUser => ({
  userId,
  userLevel: USER_TYPE_REGULAR,
  typeId: 1,
  email: `user${userId}@example.test`,
  firstName: 'Shark',
  profileUrl: `/u${userId}`,
  slideOutName: 'Shark',
  fullAddress: '',
});

describe('registration draft store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('keys drafts by signed-in member and show, plus the member being edited for admins', () => {
    expect(draftKey(36, 7)).toBe('user:7:show:36');
    expect(draftKey(36, 7, 9)).toBe('user:7:show:36:member:9');
    expect(draftKey(36, 8)).not.toBe(draftKey(36, 7));
  });

  it('stores a copy, returns it, and clears it', () => {
    const drafts = useRegistrationDraftStore();
    const form = { ...emptyForm(), donation: 25 };
    drafts.set('user:7:show:36', form);
    form.donation = 0;
    expect(drafts.get('user:7:show:36')?.donation).toBe(25);
    drafts.clear('user:7:show:36');
    expect(drafts.get('user:7:show:36')).toBeUndefined();
  });

  it('empties every draft on sign-out, so the next member starts clean', () => {
    const auth = useAuthStore();
    auth.setUser(member(7));
    const drafts = useRegistrationDraftStore();
    drafts.set(draftKey(36, 7), emptyForm());
    drafts.set(draftKey(40, 7), emptyForm());
    auth.clear();
    expect(drafts.get(draftKey(36, 7))).toBeUndefined();
    expect(drafts.get(draftKey(40, 7))).toBeUndefined();
  });

  it('does not restore a draft after the same member signs back in in the same tab', () => {
    // Document: a draft is session-scoped. Signing out and back in as the same member starts empty.
    const auth = useAuthStore();
    auth.setUser(member(7));
    const drafts = useRegistrationDraftStore();
    drafts.set(draftKey(36, 7), emptyForm());
    auth.clear();
    auth.setUser(member(7));
    expect(drafts.get(draftKey(36, 7))).toBeUndefined();
  });

  it('empties drafts when a different member signs in, and keeps them keyed apart', () => {
    const auth = useAuthStore();
    auth.setUser(member(7));
    const drafts = useRegistrationDraftStore();
    drafts.set(draftKey(36, 7), emptyForm());
    auth.setUser(member(8));
    expect(drafts.get(draftKey(36, 7))).toBeUndefined();
    expect(drafts.get(draftKey(36, 8))).toBeUndefined();
  });
});
