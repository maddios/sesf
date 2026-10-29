import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import WaiversPage from '../WaiversPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import type { RosterRow } from '../waivers';

const Page = defineComponent({ render: () => [h(WaiversPage), h(AdminConfirmHost), h(AdminToastRegion)] });

function row(overrides: Partial<RosterRow> = {}): RosterRow {
  return {
    row_key: 'registrant-1',
    kind: 'registrant',
    registration_id: 1,
    guest_id: null,
    invoice_id: 101,
    first: 'Ada',
    last: 'Lovelace',
    email: 'ada@example.test',
    status: 'not_signed',
    signed_at: null,
    checked_in: false,
    checked_in_at: null,
    arrival: 'morning 2026-10-01',
    can_request: true,
    pdf_url: null,
    ...overrides,
  };
}

type Extra = (request: SeenRequest) => Response | undefined;

/** The first lookup row is the default event (the page opens on the most recent one). */
function stub(rows: RosterRow[], extra: Extra = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.path === '/admin-api/reports/default-event') return jsonResponse(200, { data: { id: 4 } });
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }] });
    if (request.path === '/admin-api/waivers' && request.method === 'GET') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/waivers/walk-ins') return jsonResponse(200, { data: [] });
    return undefined;
  });
}

function table() {
  return screen.getByRole('table', { name: 'Waivers' });
}

async function tableCheckbox(name: string) {
  return within(await screen.findByRole('table', { name: 'Waivers' })).findByRole('checkbox', { name });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('WaiversPage', () => {
  it('opens on the default event with the legacy controls and columns, and passes axe', async () => {
    const { seen } = stub([row()]);
    const { container } = await renderAt(Page, '/admin/waivers');
    await screen.findByRole('table', { name: 'Waivers' });
    expect(await within(table()).findByText('Lovelace', { exact: false })).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/waivers')?.params.get('show_id')).toBe('4');
    expect(screen.getByText('Num Users: 1')).toBeTruthy();
    for (const label of ['Invoice ID', 'First Name', 'Last Name', 'Email', 'Signed', 'Arriving', 'Date signed']) {
      expect(within(table()).getByRole('columnheader', { name: new RegExp(label) })).toBeTruthy();
    }
    expect(screen.queryByRole('columnheader', { name: /Checked in/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'Regenerate missing PDFs' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Send waiver reminders' })).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('shows the signed status with a PDF link, and pending or not-sent rows with an Open link', async () => {
    const signed = row({ row_key: 'guest-7', kind: 'guest', guest_id: 7, first: 'Grace', last: 'Hopper', status: 'signed', can_request: false, pdf_url: '/waiver-pdf?waiverId=abc', signed_at: '2026-10-01T12:00:00Z' });
    stub([row(), row({ row_key: 'registrant-9', registration_id: 9, first: 'Kay', last: 'Nope', status: 'not_started' }), signed]);
    await renderAt(Page, '/admin/waivers');
    await screen.findByRole('table', { name: 'Waivers' });

    expect(await within(table()).findByRole('link', { name: 'Open waiver for Ada Lovelace' })).toBeTruthy();
    expect(within(table()).getByRole('link', { name: 'Open signed waiver PDF for Grace Hopper' }).getAttribute('href')).toBe('/waiver-pdf?waiverId=abc');
    expect(within(table()).getByText('Pending')).toBeTruthy();
    expect(within(table()).getByText('Not sent')).toBeTruthy();
    expect(within(table()).getByRole('link', { name: 'Open waiver for Kay Nope' }).getAttribute('href')).toBe('/admin-api/waivers/open-sign?show_id=4&registration_id=9');
  });

  it('sends waiver emails to the selected people after the legacy confirmation', async () => {
    const { seen } = stub([row()], (request) => {
      if (request.method === 'POST' && request.path === '/admin-api/waivers/request') return jsonResponse(202, { data: { queued: 1 } });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/waivers');
    await user.click(await tableCheckbox('Select Ada Lovelace'));
    await user.click(screen.getByRole('button', { name: 'Send waiver emails' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Send waiver request emails with the internal sign link to selected users?')).toBeTruthy();
    expect(seen.some((r) => r.path === '/admin-api/waivers/request')).toBe(false);
    await user.click(within(dialog).getByRole('button', { name: 'Send waiver emails' }));

    expect(await screen.findByText('1 waiver email(s) queued.')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/waivers/request')?.body).toEqual({ show_id: 4, people: [{ registration_id: 1, guest_id: null }] });
  });

  it('selects all rows and sends guests with their guest id', async () => {
    const guest = row({ row_key: 'guest-7', kind: 'guest', guest_id: 7, first: 'Grace', last: 'Hopper' });
    const { seen } = stub([row(), guest], (request) => {
      if (request.method === 'POST' && request.path === '/admin-api/waivers/request') return jsonResponse(202, { data: { queued: 2 } });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/waivers');
    await tableCheckbox('Select Ada Lovelace');
    await user.click(screen.getByRole('button', { name: 'Select all' }));
    await user.click(screen.getByRole('button', { name: 'Send waiver emails' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Send waiver emails' }));

    await waitFor(() => expect(seen.some((r) => r.path === '/admin-api/waivers/request')).toBe(true));
    expect(seen.find((r) => r.path === '/admin-api/waivers/request')?.body).toEqual({
      show_id: 4,
      people: [{ registration_id: 1, guest_id: null }, { registration_id: 1, guest_id: 7 }],
    });
  });

  it('shows the server message when the request is refused', async () => {
    stub([row()], (request) => {
      if (request.method === 'POST' && request.path === '/admin-api/waivers/request') {
        return jsonResponse(422, { message: 'That guest is not on this registration.', errors: { 'people.0.guest_id': ['That guest is not on this registration.'] } });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/waivers');
    await user.click(await tableCheckbox('Select Ada Lovelace'));
    await user.click(screen.getByRole('button', { name: 'Send waiver emails' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Send waiver emails' }));

    expect(await screen.findByText('Please correct the highlighted fields.')).toBeTruthy();
  });

  it('queues reminders after the legacy confirmation and reports the legacy message', async () => {
    const { seen } = stub([row()], (request) => {
      if (request.method === 'POST' && request.path === '/admin-api/waivers/reminders') return jsonResponse(202, { data: { queued: 3 } });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/waivers');
    await screen.findByRole('table', { name: 'Waivers' });

    await user.click(screen.getByRole('button', { name: 'Send waiver reminders' }));
    const dialog = await screen.findByRole('dialog');
    expect(seen.some((r) => r.path === '/admin-api/waivers/reminders')).toBe(false);
    await user.click(within(dialog).getByRole('button', { name: 'Send reminders' }));

    expect(await screen.findByText('Queued waiver reminder emails for 3 registrant(s) with at least one unsigned waiver in their party.')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/waivers/reminders')?.body).toEqual({ show_id: 4 });
  });

  it('lists walk-in waivers with the legacy headings, count and blank PDF link', async () => {
    stub([row()], (request) => {
      if (request.path === '/admin-api/waivers/walk-ins') {
        return jsonResponse(200, { data: [{ waiver_id: 'w1', first: 'Walk', last: 'In', email: 'walk@example.test', signed_at: null, pdf_url: '/waiver-pdf?waiverId=w1' }] });
      }
      return undefined;
    });
    await renderAt(Page, '/admin/waivers');

    expect(await screen.findByRole('heading', { name: 'Signed walk-in / QR waivers' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Open generic waiver for this event' }).getAttribute('href')).toBe('/waiver_sign/s/4');
    expect(screen.getByRole('link', { name: 'Blank waiver PDF (print)' }).getAttribute('href')).toBe('/admin-api/waivers/blank-pdf?show_id=4');
    expect(await screen.findByRole('table', { name: 'Walk-in waivers' })).toBeTruthy();
    expect(screen.getByText('Count:').closest('p')?.textContent).toContain('1');
  });
});
