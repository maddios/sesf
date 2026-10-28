import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import RegistrationGuestsPage from '../RegistrationGuestsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { toInput, type RegistrationGuest } from '../registration-guests';

function guest(overrides: Partial<RegistrationGuest> = {}): RegistrationGuest {
  return {
    id: 5,
    registration_id: 1,
    show_id: 4,
    show_name: 'Spring Show',
    user_id: 9,
    member_name: 'Ada Lovelace',
    first: 'Grace',
    last: 'Hopper',
    email: 'grace@example.test',
    type_id: 1,
    type: 'Adult',
    has_signed: false,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

/** The page plus the shared dialog/toast hosts that AdminLayout normally provides. */
const Page = defineComponent({ render: () => [h(RegistrationGuestsPage), h(AdminConfirmHost), h(AdminToastRegion)] });

function stubGuests(rows: RegistrationGuest[] = [guest()], extra: (r: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/registration-guests') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [] });
    return undefined;
  });
}

function table() {
  return screen.getByRole('table', { name: 'Registration guests' });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('RegistrationGuestsPage', () => {
  it('lists guests with the event and registrant, and passes axe', async () => {
    const { seen } = stubGuests();
    const { container } = await renderAt(Page, '/admin/registration-guests');
    expect(await within(table()).findByText('Hopper, Grace')).toBeTruthy();
    expect(within(table()).getByText('Spring Show')).toBeTruthy();
    expect(within(table()).getByText('Ada Lovelace')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/registration-guests')?.params.get('sort')).toBe('last');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('edits a guest and sends the trimmed values with the type as a number', async () => {
    const { seen } = stubGuests(undefined, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/registration-guests/5' ? jsonResponse(200, { data: guest({ type_id: 2, type: 'Child (Under 13)' }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registration-guests');
    await user.click(await within(table()).findByRole('button', { name: 'Edit guest Grace Hopper' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit Grace Hopper' });
    await user.selectOptions(within(dialog).getByLabelText('Type (required)'), '2');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'PUT')?.body).toEqual({
      first: 'Grace',
      last: 'Hopper',
      email: 'grace@example.test',
      type_id: 2,
      registration_id: 1,
    });
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Guest “Grace Hopper” saved.');
  });

  it('moves a guest to another registration and shows registration id and date added', async () => {
    const { seen } = stubGuests(undefined, (r) => (r.method === 'PUT' ? jsonResponse(200, { data: {} }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registration-guests');
    expect(await within(table()).findByRole('columnheader', { name: /Registration ID/ })).toBeTruthy();
    expect(within(table()).getByRole('columnheader', { name: /Date added/ })).toBeTruthy();
    await user.click(await within(table()).findByRole('button', { name: 'Edit guest Grace Hopper' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit Grace Hopper' });
    const registration = within(dialog).getByLabelText('Registration ID (required)');
    await user.clear(registration);
    await user.type(registration, '7');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect((seen.find((r) => r.method === 'PUT')?.body as { registration_id: number }).registration_id).toBe(7));
  });

  it('maps a registrant-email 422 to the email field', async () => {
    stubGuests(undefined, (r) =>
      r.method === 'PUT'
        ? jsonResponse(422, { message: 'Invalid.', errors: { email: ['Guest email must not be the same as primary registrant'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registration-guests');
    await user.click(await within(table()).findByRole('button', { name: 'Edit guest Grace Hopper' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit Grace Hopper' });
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await within(dialog).findByText('Guest email must not be the same as primary registrant')).toBeTruthy();
  });

  it('deletes only after the typed confirmation', async () => {
    const { seen } = stubGuests(undefined, (r) => (r.method === 'DELETE' && r.path === '/admin-api/registration-guests/5' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registration-guests');
    await user.click(await within(table()).findByRole('button', { name: 'Delete guest Grace Hopper' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete guest' });
    const confirm = within(dialog).getByRole('button', { name: 'Delete' }) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);
    await user.type(within(dialog).getByLabelText(/Type DELETE/), 'DELETE');
    await user.click(confirm);

    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));
    expect(await screen.findByText('Guest “Grace Hopper” deleted.')).toBeTruthy();
  });

  it('trims guest input and leaves an empty email null', () => {
    expect(toInput({ first: ' Ann ', last: ' Guest ', email: '  ', type_id: '1' })).toEqual({ first: 'Ann', last: 'Guest', email: null, type_id: 1, registration_id: null });
  });
});
