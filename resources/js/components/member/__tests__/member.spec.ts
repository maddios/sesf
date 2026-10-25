import { describe, it, expect } from 'vitest';
import { screen, within, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h, ref } from 'vue';
import MemberTabs from '../MemberTabs.vue';
import MemberContactModal from '../MemberContactModal.vue';
import { fetchWith, jsonResponse, mountWithRouter, requested } from '../../../pages/members/__tests__/fixtures';

describe('MemberTabs', () => {
  it('links the four private sections to their routes and marks the current tab', async () => {
    const { container } = await mountWithRouter(MemberTabs, {
      path: '/ShawnB/u44/cars',
      props: { memberName: 'ShawnB', memberId: 44 },
    });
    const nav = screen.getByRole('navigation', { name: 'Member profile sections' });
    const hrefs = within(nav).getAllByRole('link').map((a) => a.getAttribute('href'));
    expect(hrefs).toEqual(['/ShawnB/u44', '/ShawnB/u44/cars', '/ShawnB/u44/settings', '/ShawnB/u44/billing']);

    expect(within(nav).getByRole('link', { name: 'Garage' }).getAttribute('aria-current')).toBe('page');
    expect(within(nav).getByRole('link', { name: 'Profile' }).getAttribute('aria-current')).toBeNull();
    expect(container.querySelectorAll('[aria-current="page"]')).toHaveLength(1);
  });

  it('marks the profile tab on the profile URL only', async () => {
    await mountWithRouter(MemberTabs, { path: '/ShawnB/u44', props: { memberName: 'ShawnB', memberId: 44 } });
    const nav = screen.getByRole('navigation', { name: 'Member profile sections' });
    expect(within(nav).getByRole('link', { name: 'Profile' }).getAttribute('aria-current')).toBe('page');
  });

  it('encodes the member name in the links', async () => {
    await mountWithRouter(MemberTabs, { path: '/', props: { memberName: 'Shawn B', memberId: 44 } });
    const nav = screen.getByRole('navigation', { name: 'Member profile sections' });
    expect(within(nav).getByRole('link', { name: 'Billing' }).getAttribute('href')).toBe('/Shawn%20B/u44/billing');
  });

  it('has no accessibility violations', async () => {
    const { container } = await mountWithRouter(MemberTabs, { path: '/ShawnB/u44', props: { memberName: 'ShawnB', memberId: 44 } });
    expect(await axe(container)).toHaveNoViolations();
  });
});

/** Hosts the modal with a controlled open flag, like a page does. */
const Host = defineComponent({
  props: { recipientId: { type: Number, required: true } },
  setup(props) {
    const open = ref(false);
    return () => [
      h('button', { type: 'button', onClick: () => (open.value = true) }, 'Open contact'),
      h(MemberContactModal, {
        open: open.value,
        recipientId: props.recipientId,
        recipientName: 'Kai X',
        'onUpdate:open': (value: boolean) => (open.value = value),
      }),
    ];
  },
});

describe('MemberContactModal', () => {
  it('blocks sending when the subject or message is blank', async () => {
    const user = userEvent.setup();
    const fn = fetchWith(() => jsonResponse(200, ''));
    await mountWithRouter(Host, { path: '/', props: { recipientId: 44 } });
    await user.click(screen.getByRole('button', { name: 'Open contact' }));
    const dialog = await screen.findByRole('dialog', { name: 'Contact Kai X' });

    await user.click(within(dialog).getByRole('button', { name: 'Send message' }));
    expect(await within(dialog).findByText('Enter a subject and a message before sending.')).toBeTruthy();
    expect(requested(fn)).toEqual([]);
  });

  it('posts the trimmed message, shows success and clears the fields', async () => {
    const user = userEvent.setup();
    const fn = fetchWith(() => jsonResponse(200, ''));
    await mountWithRouter(Host, { path: '/', props: { recipientId: 44 } });
    await user.click(screen.getByRole('button', { name: 'Open contact' }));
    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByLabelText('Subject'), '  Hi  ');
    await user.type(within(dialog).getByLabelText('Message'), 'Great build');
    await user.click(within(dialog).getByRole('button', { name: 'Send message' }));

    expect(await within(dialog).findByText('Your message was sent.')).toBeTruthy();
    expect(fn.mock.calls[0]?.[1]?.method).toBe('POST');
    expect(JSON.parse(String(fn.mock.calls[0]?.[1]?.body))).toEqual({ user_id: 44, subject: 'Hi', message: 'Great build' });
    expect((within(dialog).getByLabelText('Subject') as HTMLInputElement).value).toBe('');
  });

  it('shows the generic failure for a server error and resets the status on close', async () => {
    const user = userEvent.setup();
    fetchWith(() => jsonResponse(401, ['Invalid User']));
    await mountWithRouter(Host, { path: '/', props: { recipientId: 44 } });
    await user.click(screen.getByRole('button', { name: 'Open contact' }));
    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByLabelText('Subject'), 'Hi');
    await user.type(within(dialog).getByLabelText('Message'), 'Hello');
    await user.click(within(dialog).getByRole('button', { name: 'Send message' }));
    expect(await within(dialog).findByText('Your message could not be sent. Please try again later.')).toBeTruthy();

    await user.click(within(dialog).getByRole('button', { name: 'Close dialog' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await user.click(screen.getByRole('button', { name: 'Open contact' }));
    const reopened = await screen.findByRole('dialog');
    expect(within(reopened).queryByText('Your message could not be sent. Please try again later.')).toBeNull();
  });

  it('has no accessibility violations when open', async () => {
    const user = userEvent.setup();
    fetchWith(() => jsonResponse(200, ''));
    await mountWithRouter(Host, { path: '/', props: { recipientId: 44 } });
    await user.click(screen.getByRole('button', { name: 'Open contact' }));
    const dialog = await screen.findByRole('dialog');
    expect(await axe(dialog)).toHaveNoViolations();
  });
});
