import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, h, ref } from 'vue';
import ContactModal from '../ContactModal.vue';
import { callsTo, jsonResponse, stubFetch, SESSION_PAYLOAD } from '../../../test/helpers';
import { useAuthStore, type AuthUser } from '../../../stores/auth';
import { CONTACT_LIMITS, validateContact } from '../contactValidation';

const REPORT = 'POST /home/report';

const SIGNED_IN: AuthUser = {
  userId: 7,
  userLevel: 1,
  typeId: 1,
  email: SESSION_PAYLOAD.email,
  firstName: SESSION_PAYLOAD.first_name,
  profileUrl: SESSION_PAYLOAD.profileURL,
  slideOutName: SESSION_PAYLOAD.slideOutName,
  fullAddress: SESSION_PAYLOAD.fullAddress,
};

/** Hosts the modal with a v-model:open so the dialog can be closed from inside it. */
function renderModal(open = true, signedIn = false) {
  const pinia = createPinia();
  setActivePinia(pinia);
  if (signedIn) {
    useAuthStore(pinia).setUser(SIGNED_IN);
  }
  const Host = defineComponent({
    setup() {
      const isOpen = ref(open);
      return () => h(ContactModal, { open: isOpen.value, 'onUpdate:open': (value: boolean) => (isOpen.value = value) });
    },
  });
  const utils = render(Host, { global: { plugins: [pinia] } });
  return { pinia, ...utils };
}

beforeEach(() => {
  setActivePinia(createPinia());
});

describe('ContactModal', () => {
  it('opens as a labelled dialog with the anonymous fields', () => {
    stubFetch();
    renderModal();
    expect(screen.getByRole('dialog', { name: 'Contact us' })).toBeTruthy();
    expect(screen.getByLabelText('Your name')).toBeTruthy();
    expect(screen.getByLabelText('Email', { selector: 'input' })).toBeTruthy();
    expect(screen.getByLabelText('Subject')).toBeTruthy();
    expect(screen.getByLabelText('Message')).toBeTruthy();
  });

  it('hides the name and email fields for signed-in members', () => {
    stubFetch();
    renderModal(true, true);
    expect(screen.queryByLabelText('Your name')).toBeNull();
    expect(screen.queryByLabelText('Email', { selector: 'input' })).toBeNull();
    expect(screen.getByLabelText('Subject')).toBeTruthy();
  });

  it('has no detectable accessibility violations', async () => {
    stubFetch();
    const { baseElement } = renderModal();
    expect(await axe(baseElement)).toHaveNoViolations();
  });

  it('shows the message length counter with the limit', async () => {
    stubFetch();
    const user = userEvent.setup();
    renderModal(true, true);
    await user.type(screen.getByLabelText('Message'), 'Hello');
    expect(screen.getByText(`5 of ${CONTACT_LIMITS.message} characters`)).toBeTruthy();
    expect((screen.getByLabelText('Subject') as HTMLInputElement).maxLength).toBe(CONTACT_LIMITS.subject);
    expect((screen.getByLabelText('Message') as HTMLTextAreaElement).maxLength).toBe(CONTACT_LIMITS.message);
  });

  it('validates an anonymous sender without calling the server', async () => {
    const fetchMock = stubFetch();
    const user = userEvent.setup();
    renderModal();
    await user.click(screen.getByRole('button', { name: 'Send' }));
    expect(screen.getByText('Enter your name.')).toBeTruthy();
    expect(screen.getByText('Enter your email address.')).toBeTruthy();
    expect(screen.getByText('Enter a subject.')).toBeTruthy();
    expect(screen.getByText('Enter a message.')).toBeTruthy();
    expect(callsTo(fetchMock, REPORT)).toHaveLength(0);
  });

  it('sends name, email, subject and message for an anonymous sender, then shows success', async () => {
    const fetchMock = stubFetch({ [REPORT]: () => new Response(null, { status: 200 }) });
    const user = userEvent.setup();
    renderModal();
    await user.type(screen.getByLabelText('Your name'), ' Shark Fan ');
    await user.type(screen.getByLabelText('Email', { selector: 'input' }), 'fan@example.test');
    await user.type(screen.getByLabelText('Subject'), 'Broken link');
    await user.type(screen.getByLabelText('Message'), 'The gallery link is broken.');
    await user.click(screen.getByRole('button', { name: 'Send' }));

    expect(await screen.findByText('Thank you. Your message was sent.')).toBeTruthy();
    expect(callsTo(fetchMock, REPORT)).toEqual([
      {
        name: 'Shark Fan',
        email: 'fan@example.test',
        subject: 'Broken link',
        message: 'The gallery link is broken.',
      },
    ]);
    expect((screen.getByLabelText('Subject') as HTMLInputElement).value).toBe('');
    expect((screen.getByLabelText('Message') as HTMLTextAreaElement).value).toBe('');
  });

  it('sends only subject and message for a signed-in member', async () => {
    const fetchMock = stubFetch({ [REPORT]: () => new Response(null, { status: 200 }) });
    const user = userEvent.setup();
    renderModal(true, true);
    await user.type(screen.getByLabelText('Subject'), 'Judging question');
    await user.type(screen.getByLabelText('Message'), 'When are results posted?');
    await user.click(screen.getByRole('button', { name: 'Send' }));

    await waitFor(() => expect(callsTo(fetchMock, REPORT)).toHaveLength(1));
    const [sent] = callsTo(fetchMock, REPORT) as Array<Record<string, unknown>>;
    expect(sent).toEqual({ subject: 'Judging question', message: 'When are results posted?' });
    expect(await screen.findByText('Thank you. Your message was sent.')).toBeTruthy();
  });

  it('shows the server message when sending fails, and keeps what was typed', async () => {
    stubFetch({ [REPORT]: () => jsonResponse(400, ['Subject is too long.']) });
    const user = userEvent.setup();
    renderModal(true, true);
    await user.type(screen.getByLabelText('Subject'), 'Hi');
    await user.type(screen.getByLabelText('Message'), 'Body text');
    await user.click(screen.getByRole('button', { name: 'Send' }));

    expect((await screen.findByRole('alert')).textContent).toContain('Subject is too long.');
    expect((screen.getByLabelText('Message') as HTMLTextAreaElement).value).toBe('Body text');
  });

  it('uses the generic failure text when the server gives no usable message', async () => {
    stubFetch({ [REPORT]: () => new Response('<html>', { status: 500 }) });
    const user = userEvent.setup();
    renderModal(true, true);
    await user.type(screen.getByLabelText('Subject'), 'Hi');
    await user.type(screen.getByLabelText('Message'), 'Body');
    await user.click(screen.getByRole('button', { name: 'Send' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Your message could not be sent. Please try again.');
  });

  it('is closed by Cancel', async () => {
    stubFetch();
    const user = userEvent.setup();
    renderModal();
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('closes with the header close button', async () => {
    stubFetch();
    const user = userEvent.setup();
    renderModal();
    await user.click(screen.getByRole('button', { name: 'Close contact form' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('renders the dialog only while the open prop is true', async () => {
    stubFetch();
    const onUpdate = vi.fn();
    const pinia = createPinia();
    setActivePinia(pinia);
    const utils = render(ContactModal, { props: { open: false, 'onUpdate:open': onUpdate }, global: { plugins: [pinia] } });
    expect(screen.queryByRole('dialog')).toBeNull();
    await utils.rerender({ open: true, 'onUpdate:open': onUpdate });
    expect(screen.getByRole('dialog', { name: 'Contact us' })).toBeTruthy();
  });
});

describe('validateContact', () => {
  const base = { name: 'Shark', email: 'fan@example.test', subject: 'Hi', message: 'Body' };

  it('requires name and email only for anonymous senders', () => {
    expect(validateContact({ ...base, name: '', email: '' }, true)).toEqual({
      name: 'Enter your name.',
      email: 'Enter your email address.',
    });
    expect(validateContact({ ...base, name: '', email: '' }, false)).toEqual({});
  });

  it('rejects an invalid or overlong email and overlong name for anonymous senders', () => {
    expect(validateContact({ ...base, email: 'nope' }, true).email).toBe('Enter a valid email address.');
    expect(validateContact({ ...base, email: `${'a'.repeat(55)}@x.test` }, true).email).toBe(
      `Email must be ${CONTACT_LIMITS.email} characters or fewer.`,
    );
    expect(validateContact({ ...base, name: 'n'.repeat(CONTACT_LIMITS.name + 1) }, true).name).toBe(
      `Name must be ${CONTACT_LIMITS.name} characters or fewer.`,
    );
  });

  it('limits the subject and message lengths', () => {
    expect(validateContact({ ...base, subject: 's'.repeat(CONTACT_LIMITS.subject + 1) }, false).subject).toBe(
      `Subject must be ${CONTACT_LIMITS.subject} characters or fewer.`,
    );
    expect(validateContact({ ...base, message: 'm'.repeat(CONTACT_LIMITS.message + 1) }, false).message).toBe(
      `Message must be ${CONTACT_LIMITS.message} characters or fewer.`,
    );
    expect(validateContact({ ...base, subject: '  ', message: '' }, false)).toEqual({
      subject: 'Enter a subject.',
      message: 'Enter a message.',
    });
  });

  it('is pure: a valid anonymous form has no errors', () => {
    expect(validateContact(base, true)).toEqual({});
  });
});
