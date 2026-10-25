import { describe, it, expect, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { draftKey, useRegistrationDraftStore } from '../useRegistrationDraft';
import { emptyForm } from '../form';

describe('registration draft store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('keys drafts by show, and by member too for admins', () => {
    expect(draftKey(36)).toBe('show:36');
    expect(draftKey(36, 9)).toBe('show:36:user:9');
  });

  it('stores a copy, returns it, and clears it', () => {
    const drafts = useRegistrationDraftStore();
    const form = { ...emptyForm(), donation: 25 };
    drafts.set('show:36', form);
    form.donation = 0;
    expect(drafts.get('show:36')?.donation).toBe(25);
    drafts.clear('show:36');
    expect(drafts.get('show:36')).toBeUndefined();
  });
});
