import { afterEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import CheckinPage from '../CheckinPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { jsonResponse, renderAt } from '../../../../test/helpers';
import { stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { useToast } from '../../../../composables/admin/useToast';
import type { CheckinRow } from '../checkin';

const Page = defineComponent({ render: () => [h(CheckinPage), h(AdminConfirmHost), h(AdminToastRegion)] });

function row(overrides: Partial<CheckinRow> = {}): CheckinRow {
  return {
    id: 11,
    invoice_id: 7,
    primary_name: 'Ada Lovelace',
    primary_email: 'ada@example.com',
    is_paid: true,
    is_checked_in: false,
    checked_in_at: null,
    arrival_date: '2026-05-01',
    arrival_time: 'morning',
    party_complete: true,
    party: [
      { role: 'primary', name: 'Ada Lovelace', signed: true, guest_id: null },
      { role: 'guest', name: 'Pat Guest', signed: true, guest_id: 5 },
    ],
    shirt_lines: [{ id: 91, type_id: 2, type_name: 'Mens Medium', handed_out: false }],
    ...overrides,
  };
}

function cardOf(name: string): HTMLElement {
  return screen.getAllByText(name)[0].closest('tr') as HTMLElement;
}

async function waitForCard(name: string): Promise<HTMLElement> {
  await waitFor(() => expect(screen.getAllByText(name).length).toBeGreaterThan(0));
  return cardOf(name);
}

function stubCheckin(rows: CheckinRow[], extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/checkin/registrations') return jsonResponse(200, { data: rows });
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }] });
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('CheckinPage', () => {
  it('lists the event registrations with the legacy columns and passes axe', async () => {
    const { seen } = stubCheckin([
      row(),
      row({ id: 12, invoice_id: 8, primary_name: 'Grace Hopper', is_paid: false, party_complete: false, shirt_lines: [], party: [{ role: 'primary', name: 'Grace Hopper', signed: false, guest_id: null }] }),
    ]);
    const { container } = await renderAt(Page, '/admin/checkin?show_id=4');

    const list = await screen.findByRole('table', { name: 'Registrations' });
    expect(within(list).getByText('Incomplete')).toBeTruthy();
    expect(within(list).getByText('Unpaid')).toBeTruthy();
    expect(within(list).getByText('Mens Medium')).toBeTruthy();
    expect(within(list).getByText('None')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/checkin/registrations')!.params.get('show_id')).toBe('4');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('searches by name, guest and invoice number', async () => {
    stubCheckin([row(), row({ id: 12, invoice_id: 8, primary_name: 'Grace Hopper', party: [{ role: 'primary', name: 'Grace Hopper', signed: true, guest_id: null }] })]);
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    const list = await screen.findByRole('table', { name: 'Registrations' });

    await user.type(screen.getByLabelText(/Narrow by name/), 'pat');
    expect(within(list).queryByText('Grace Hopper')).toBeNull();
    expect(within(list).getAllByText('Ada Lovelace').length).toBeGreaterThan(0);

    await user.clear(screen.getByLabelText(/Narrow by name/));
    await user.type(screen.getByLabelText(/Narrow by name/), '#8');
    expect(within(list).getAllByText('Grace Hopper').length).toBeGreaterThan(0);
    expect(within(list).queryByText('Ada Lovelace')).toBeNull();
  });

  it('checks in a complete, paid party from the check-in modal without override', async () => {
    const { seen } = stubCheckin([row()], (request) => {
      if (request.method === 'POST' && request.path.endsWith('/check-in')) {
        return jsonResponse(200, { data: row({ is_checked_in: true, checked_in_at: '2026-05-01T09:00:00+00:00' }) });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await within(await waitForCard('Ada Lovelace')).findByRole('button', { name: 'Check-in' }));
    await user.click(await screen.findByRole('button', { name: 'Confirm check-in' }));

    await waitFor(() => expect(seen.find((r) => r.path.endsWith('/check-in'))?.body).toEqual({ override: false }));
    expect(await within(cardOf('Ada Lovelace')).findByText('Checked in')).toBeTruthy();
  });

  it('asks with the legacy warning before an override of an unpaid party', async () => {
    const { seen } = stubCheckin([row({ is_paid: false })], (request) => {
      if (request.method === 'POST' && request.path.endsWith('/check-in')) return jsonResponse(200, { data: row({ is_checked_in: true }) });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await within(await waitForCard('Ada Lovelace')).findByRole('button', { name: 'Check-in' }));
    await user.click(await screen.findByRole('button', { name: 'Confirm check-in' }));

    const dialog = await screen.findByRole('dialog', { name: 'Check in anyway?' });
    expect(within(dialog).getByText('Warning: registration is not marked paid. Check in anyway?')).toBeTruthy();
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(seen.some((r) => r.path.endsWith('/check-in'))).toBe(false);

    await user.click(await screen.findByRole('button', { name: 'Confirm check-in' }));
    await user.click(await screen.findByRole('button', { name: 'Check in anyway' }));
    await waitFor(() => expect(seen.find((r) => r.path.endsWith('/check-in'))?.body).toEqual({ override: true }));
  });

  it('shows the server reason inside the modal when a check-in is refused', async () => {
    stubCheckin([row()], (request) => {
      if (request.method === 'POST' && request.path.endsWith('/check-in')) {
        return jsonResponse(422, { message: 'x', errors: { registration: ['Cannot check in: registration is not marked paid.'] } });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await within(await waitForCard('Ada Lovelace')).findByRole('button', { name: 'Check-in' }));
    await user.click(await screen.findByRole('button', { name: 'Confirm check-in' }));

    expect(await screen.findByText('Cannot check in: registration is not marked paid.')).toBeTruthy();
  });

  it('offers the waiver reminder only while the party is incomplete, and sends it after confirmation', async () => {
    const { seen } = stubCheckin([row({ party_complete: false, party: [{ role: 'primary', name: 'Ada Lovelace', signed: false, guest_id: null }] })], (request) => {
      if (request.method === 'POST' && request.path.endsWith('/waiver-reminder')) return jsonResponse(200, { ok: true, queued: true });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await within(await waitForCard('Ada Lovelace')).findByRole('button', { name: 'Check-in' }));
    await user.click(await screen.findByRole('button', { name: 'Send waiver reminder' }));
    const dialog = await screen.findByRole('dialog', { name: 'Waiver reminder' });
    await user.click(within(dialog).getByRole('button', { name: 'Send reminder' }));

    await waitFor(() => expect(seen.some((r) => r.path.endsWith('/waiver-reminder'))).toBe(true));
    expect(await screen.findByText('Reminder queued for primary email.')).toBeTruthy();
  });

  it('opens the waiver form of an unsigned party member in the modal', async () => {
    stubCheckin([row({ party_complete: false, party: [{ role: 'primary', name: 'Ada Lovelace', signed: false, guest_id: null }] })], (request) => {
      if (request.method === 'POST' && request.path.endsWith('/waiver-sign-url')) {
        return jsonResponse(200, { ok: true, url: 'https://example.test/waiver_sign/t/abc', personName: 'Ada Lovelace' });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/checkin?show_id=4');
    await user.click(await within(await waitForCard('Ada Lovelace')).findByRole('button', { name: 'Check-in' }));
    await user.click(await screen.findByRole('button', { name: 'Sign' }));

    const frame = await screen.findByTitle('Ada Lovelace — Waiver');
    expect(frame.getAttribute('src')).toBe('https://example.test/waiver_sign/t/abc?ui=embedded');
  });

  it('asks for an event first', async () => {
    stubCheckin([]);
    const { container } = await renderAt(Page, '/admin/checkin');
    expect(await screen.findByText('Choose an event to start checking people in.')).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
  });
});
