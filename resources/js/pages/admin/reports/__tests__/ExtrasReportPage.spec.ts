import { describe, it, expect, afterEach } from 'vitest';
import { screen, within } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import ExtrasReportPage from '../ExtrasReportPage.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';

const Page = defineComponent({ render: () => [h(ExtrasReportPage), h(AdminToastRegion)] });

function stub() {
  return stubAdminFetch((request) => {
    if (request.path === '/admin-api/reports/default-event') return jsonResponse(200, { data: { id: 4 } });
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }] });
    if (request.path === '/admin-api/reports/extras') {
      return jsonResponse(200, listBody([{ id: 9, name: 'Lunch', num_total: 5, num_paid: 2 }]));
    }
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
});

describe('ExtrasReportPage', () => {
  it('lists quantities per extra for the chosen event, with an export link, and passes axe', async () => {
    const { seen } = stub();
    const { container } = await renderAt(Page, '/admin/reports/extras');

    const table = await screen.findByRole('table', { name: 'Extras' });
    expect(await within(table).findByText('Lunch')).toBeTruthy();
    expect(within(table).getByText('9')).toBeTruthy();
    expect(within(table).getByText('5')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/reports/extras')?.params.get('show_id')).toBe('4');
    expect(screen.getByRole('link', { name: 'Export extras summary to Excel' }).getAttribute('href')).toBe('/admin-api/reports/extras/export.xls?show_id=4');
    expect(await axe(container)).toHaveNoViolations();
  });
});
