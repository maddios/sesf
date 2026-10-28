import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import MembersReportPage from '../MembersReportPage.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';

const Page = defineComponent({ render: () => [h(MembersReportPage), h(AdminToastRegion)] });

const MEMBERS = [{ id: 1, first: 'Ada', last: 'Lovelace', email: 'ada@example.test' }];

function stub() {
  return stubAdminFetch((request) => {
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }] });
    if (request.path === '/admin-api/reports/members') return jsonResponse(200, listBody(MEMBERS));
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
});

describe('MembersReportPage', () => {
  it('lists members with an export link for the same filters, and passes axe', async () => {
    const { seen } = stub();
    const { container, router } = await renderAt(Page, '/admin/reports/members?f.is_paid=1');
    const table = await screen.findByRole('table', { name: 'Members' });
    expect(await within(table).findByText('ada@example.test')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/reports/members')?.params.get('filter[is_paid]')).toBe('1');
    expect(screen.getByRole('link', { name: 'Export members to Excel' }).getAttribute('href')).toContain('/admin-api/reports/members/export.xls?');
    expect(screen.getByRole('link', { name: 'Export members to Excel' }).getAttribute('href')).toContain('filter%5Bis_paid%5D=1');
    expect(router.currentRoute.value.query['f.is_paid']).toBe('1');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('refetches and syncs the URL when a preference filter changes', async () => {
    const { seen } = stub();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/reports/members');
    await screen.findByRole('table', { name: 'Members' });

    await user.selectOptions(screen.getByLabelText('Allow system Email'), '0');

    await waitFor(() => expect(router.currentRoute.value.query['f.allow_system_email']).toBe('0'));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/reports/members').at(-1)?.params.get('filter[allow_system_email]')).toBe('0'));
  });
});
