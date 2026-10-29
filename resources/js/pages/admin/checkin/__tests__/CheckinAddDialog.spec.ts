import { afterEach, describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import CheckinAddDialog from '../components/CheckinAddDialog.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { jsonResponse, renderAt } from '../../../../test/helpers';
import { stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';

const Page = defineComponent({ render: () => [h(CheckinAddDialog, { showId: 4, onCreated: () => undefined, onClose: () => undefined }), h(AdminToastRegion)] });

const options = {
  show_name: 'Spring',
  show_dates: [{ data: '2026-05-01', name: 'Friday May-01' }],
  eligible_users: [{ id: 7, label: 'Ada Lovelace — ada@example.com' }],
  guest_types: [{ id: 1, name: 'Adult' }],
  shirt_types: [{ id: 2, name: 'Mens Medium', price_cents: 2000 }],
  extras: [],
  donations: [{ id: 0, name: 'No thanks!' }],
  arrival_times: ['morning'],
};

function stub(extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.path === '/admin-api/checkin/shows/4/registration-options') return jsonResponse(200, { data: options });
    if (request.path === '/admin-api/checkin/users/7/garage') {
      return jsonResponse(200, { data: [{ id: 3, year: 1969, model: 'Camaro', body_name: 'Coupe', label: '1969 Coupe Camaro', judging_types: [] }] });
    }
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
});

describe('CheckinAddDialog', () => {
  it('loads the form choices, finds a member, and loads the garage cars', async () => {
    const { seen } = stub();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await screen.findByRole('combobox', { name: /Site user/ }));
    await user.click(await screen.findByRole('option', { name: 'Ada Lovelace — ada@example.com' }));

    expect(await screen.findByLabelText('1969 Coupe Camaro')).toBeTruthy();
    expect(seen.some((r) => r.path === '/admin-api/checkin/users/7/garage' && r.params.get('show_id') === '4')).toBe(true);
  });

  it('checks the form in the legacy order and shows the legacy message', async () => {
    stub();
    const user = userEvent.setup();
    const { container } = await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Create & email' }));

    expect(await screen.findByText('Choose a member from the list (type to filter, then click a row).')).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('posts the payload and reports the server reason on refusal', async () => {
    const { seen } = stub((request) => {
      if (request.method === 'POST' && request.path === '/admin-api/checkin/shows/4/registrations') {
        return jsonResponse(409, { message: 'This member already has a registration record for this event' });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await screen.findByRole('combobox', { name: /Site user/ }));
    await user.click(await screen.findByRole('option', { name: 'Ada Lovelace — ada@example.com' }));
    await waitFor(() => expect(screen.getByLabelText('1969 Coupe Camaro')).toBeTruthy());
    await user.click(screen.getByLabelText('1969 Coupe Camaro'));
    await user.click(screen.getByRole('combobox', { name: 'Date' }));
    await user.click(await screen.findByRole('option', { name: 'Friday May-01 (2026-05-01)' }));
    await user.click(screen.getByRole('combobox', { name: 'Approximate arrival' }));
    await user.click(await screen.findByRole('option', { name: 'Morning' }));
    await user.click(screen.getByRole('button', { name: 'Create & email' }));

    expect(await screen.findByText('This member already has a registration record for this event')).toBeTruthy();
    const post = seen.find((r) => r.path === '/admin-api/checkin/shows/4/registrations')!;
    expect(post.body).toMatchObject({ user_id: 7, arrival_date: '2026-05-01', arrival_time: 'morning', cars: [{ id: 3, judging_type_id: null }], guests: [], shirts: [], extras: [] });
  });

  it('says so when the registration was created but its emails could not be queued', async () => {
    stub((request) => {
      if (request.method === 'POST' && request.path === '/admin-api/checkin/shows/4/registrations') {
        return jsonResponse(201, { data: { id: 11 }, queued: false, message: 'The email could not be queued' });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await screen.findByRole('combobox', { name: /Site user/ }));
    await user.click(await screen.findByRole('option', { name: 'Ada Lovelace — ada@example.com' }));
    await waitFor(() => expect(screen.getByLabelText('1969 Coupe Camaro')).toBeTruthy());
    await user.click(screen.getByLabelText('1969 Coupe Camaro'));
    await user.click(screen.getByRole('combobox', { name: 'Date' }));
    await user.click(await screen.findByRole('option', { name: 'Friday May-01 (2026-05-01)' }));
    await user.click(screen.getByRole('combobox', { name: 'Approximate arrival' }));
    await user.click(await screen.findByRole('option', { name: 'Morning' }));
    await user.click(screen.getByRole('button', { name: 'Create & email' }));

    expect(await screen.findByText(/emails could not be queued/)).toBeTruthy();
  });
});
