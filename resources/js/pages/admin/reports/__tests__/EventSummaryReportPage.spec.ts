import { describe, it, expect, afterEach } from 'vitest';
import { screen, within } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import EventSummaryReportPage from '../EventSummaryReportPage.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import type { EventRegistrationRow } from '../reports';

const Page = defineComponent({ render: () => [h(EventSummaryReportPage), h(AdminToastRegion)] });

const TOTALS = { registrations: 12, guests: 3, paid_registrations: 9, paid_guests: 2, extras: 4, paid_extras: 3, tshirts: 10, paid_tshirts: 8 };

const REGISTRATION: EventRegistrationRow = {
  id: 1,
  invoice_id: 2001,
  first: 'Ada',
  last: 'Lovelace',
  email: 'ada@example.test',
  address1: '1 Main St',
  address2: null,
  city: 'Macon',
  state: 'GA',
  zip: '31201',
  is_paid: true,
  user_signed_waiver: true,
  judged: false,
  cars: ['1966 Ford Mustang'],
  guests: [{ name: 'Grace Hopper', signed: true }],
  shirts: ['Large'],
  extras: [{ name: 'Lunch', count: 2 }],
  arrival_time: 'morning',
  arrival_date: '2026-10-01',
};

function stub() {
  return stubAdminFetch((request) => {
    if (request.path === '/admin-api/reports/default-event') return jsonResponse(200, { data: { id: 4 } });
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }] });
    if (request.path === '/admin-api/reports/event-summary/totals') return jsonResponse(200, { data: TOTALS });
    if (request.path === '/admin-api/reports/event-summary') return jsonResponse(200, listBody([REGISTRATION]));
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
});

describe('EventSummaryReportPage', () => {
  it('shows the totals, the registrations and the workbook export for the chosen event, and passes axe', async () => {
    const { seen } = stub();
    const { container } = await renderAt(Page, '/admin/reports/event-summary');

    expect(await screen.findByLabelText('Event totals')).toBeTruthy();
    expect(await screen.findByText('12')).toBeTruthy();
    const table = screen.getByRole('table', { name: 'Event registrations' });
    expect(await within(table).findByText('Lovelace')).toBeTruthy();
    expect(within(table).getByText('Ada')).toBeTruthy();
    expect(within(table).getByText('1966 Ford Mustang')).toBeTruthy();
    expect(within(table).getByText('2x Lunch')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/reports/event-summary')?.params.get('show_id')).toBe('4');
    expect(screen.getByRole('link', { name: 'Export event summary to Excel' }).getAttribute('href')).toBe('/admin-api/reports/event-summary/export.xls?show_id=4');
    expect(await axe(container)).toHaveNoViolations();
  });
});
