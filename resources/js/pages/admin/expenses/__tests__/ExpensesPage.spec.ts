import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import ExpensesPage from '../ExpensesPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { stubAdminFetch, type SeenRequest, chooseRowAction } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { formatAmount, toForm, toInput, type Expense, type ExpenseLedger } from '../expenses';

function line(overrides: Partial<Expense> = {}): Expense {
  return {
    id: 1,
    show_id: 4,
    description: 'Tent rental',
    amount: '1300.10',
    absolute_amount: '1300.10',
    type: 'expense',
    created_at: '2026-02-01T15:00:00+00:00',
    updated_at: null,
    ...overrides,
  };
}

const LEDGER: ExpenseLedger = {
  data: [line(), line({ id: 2, description: 'Sponsor check', amount: '-200.00', absolute_amount: '200.00', type: 'income' })],
  totals: { registration_fees: '1500.10', net_received: '995.50', total_expenses: '1100.10', gross: '-104.60' },
};

const Page = defineComponent({ render: () => [h(ExpensesPage), h(AdminConfirmHost), h(AdminToastRegion)] });

function stubExpenses(extra: (request: SeenRequest) => Response | undefined = () => undefined, ledger: ExpenseLedger = LEDGER) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/expenses') return jsonResponse(200, ledger);
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }, { id: 5, label: 'Fall (2026)' }] });
    return undefined;
  });
}

function lines(): Promise<HTMLElement> {
  return screen.findByRole('table', { name: 'Expense and Income' });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('ExpensesPage', () => {
  it('asks for an event first and loads nothing', async () => {
    const { seen } = stubExpenses();
    await renderAt(Page, '/admin/expenses');
    expect(await screen.findByText('Choose an event to see its expenses.')).toBeTruthy();
    expect(seen.some((r) => r.path === '/admin-api/expenses')).toBe(false);
  });

  it('shows the legacy income, lines and totals for the event in the URL, and passes axe', async () => {
    const { seen } = stubExpenses();
    const { container } = await renderAt(Page, '/admin/expenses?show_id=4');

    expect(await within(await lines()).findByText('Tent rental')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/expenses')?.params.get('show_id')).toBe('4');
    expect(screen.getByText('Registration Fees').nextElementSibling?.textContent?.trim()).toBe('$1,500.10');
    expect(screen.getByText('Net (Actual received registration fees)').nextElementSibling?.textContent?.trim()).toBe('$995.50');
    const expenseCell = within(await lines()).getByText('($1,300.10)');
    expect(expenseCell.className).toContain('text-[#a94442]');
    expect(within(await lines()).getByText('$200.00').className).not.toContain('text-[#a94442]');
    expect(within(await lines()).getAllByText('Feb 1, 2026')).toHaveLength(2);
    expect(screen.getByText('Total Expenses').nextElementSibling?.textContent?.trim()).toBe('($1,100.10)');
    const gross = screen.getByText('Gross (Registration fees - Expenses)').nextElementSibling;
    expect(gross?.textContent?.trim()).toBe('($104.60)');
    expect(gross?.className).toContain('text-[#a94442]');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('shows net income and a positive gross without parentheses', async () => {
    stubExpenses(undefined, { data: [], totals: { registration_fees: '0.00', net_received: '100.00', total_expenses: '-50.00', gross: '150.00' } });
    await renderAt(Page, '/admin/expenses?show_id=4');
    expect(await screen.findByText('No expenses or income yet.')).toBeTruthy();
    expect(screen.getByText('Total Expenses').nextElementSibling?.textContent?.trim()).toBe('-$50.00');
    expect(screen.getByText('Gross (Registration fees - Expenses)').nextElementSibling?.textContent?.trim()).toBe('$150.00');
  });

  it('switches events through the picker and keeps the choice in the URL', async () => {
    const { seen } = stubExpenses();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/expenses?show_id=4');
    await within(await lines()).findByText('Tent rental');
    await user.clear(screen.getByRole('combobox', { name: 'Event' }));
    await user.type(screen.getByRole('combobox', { name: 'Event' }), 'fall');
    await user.click(await screen.findByRole('option', { name: 'Fall (2026)' }));

    await waitFor(() => expect(router.currentRoute.value.query.show_id).toBe('5'));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/expenses').at(-1)?.params.get('show_id')).toBe('5'));
  });

  it('adds an income line for the chosen event', async () => {
    const { seen } = stubExpenses((r) => (r.method === 'POST' && r.path === '/admin-api/expenses' ? jsonResponse(201, { data: line({ id: 3 }) }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/expenses?show_id=4');
    await within(await lines()).findByText('Tent rental');
    await user.click(screen.getByRole('button', { name: 'New item' }));
    const dialog = await screen.findByRole('dialog', { name: 'New item' });
    await user.type(within(dialog).getByLabelText('Description'), 'Raffle');
    await user.type(within(dialog).getByLabelText('Amount (required)'), '1,250.5');
    await user.click(within(dialog).getByRole('combobox', { name: /^Type/ }));
    await user.click(await screen.findByRole('option', { name: 'Income' }));
    await user.click(within(dialog).getByRole('button', { name: 'Add item' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/expenses')).toBe(true));
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/expenses')?.body).toEqual({ show_id: 4, description: 'Raffle', amount: '1250.50', type: 'income' });
    expect(await screen.findByText('Item added.')).toBeTruthy();
    await waitFor(() => expect(seen.filter((r) => r.method === 'GET' && r.path === '/admin-api/expenses')).toHaveLength(2));
  });

  it('edits a line with its current values and maps a 422 onto the field', async () => {
    let attempts = 0;
    const { seen } = stubExpenses((r) => {
      if (r.method !== 'PUT' || r.path !== '/admin-api/expenses/2') return undefined;
      attempts += 1;
      return attempts === 1
        ? jsonResponse(422, { message: 'Invalid', errors: { amount: ['The amount field must not be greater than 999999.99.'] } })
        : jsonResponse(200, { data: line({ id: 2 }) });
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/expenses?show_id=4');
    await user.click(await within(await lines()).findByRole('button', { name: 'Edit Sponsor check' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit item' });
    expect((within(dialog).getByLabelText('Amount (required)') as HTMLInputElement).value).toBe('200.00');
    expect((within(dialog).getByRole('combobox', { name: /^Type/ }) as HTMLInputElement).value).toBe('Income');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    expect(await within(dialog).findByText('The amount field must not be greater than 999999.99.')).toBeTruthy();
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await screen.findByText('Item saved.')).toBeTruthy();
    expect(seen.filter((r) => r.method === 'PUT').at(-1)?.body).toEqual({ description: 'Sponsor check', amount: '200.00', type: 'income' });
  });

  it('deletes a line only after confirmation', async () => {
    const { seen } = stubExpenses((r) => (r.method === 'DELETE' && r.path === '/admin-api/expenses/1' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/expenses?show_id=4');
    await chooseRowAction(user, await lines(), 'Tent rental', 'Delete…');
    await user.click(await screen.findByRole('button', { name: 'Cancel' }));
    expect(seen.some((r) => r.method === 'DELETE')).toBe(false);

    await chooseRowAction(user, await lines(), 'Tent rental', 'Delete…');
    const dialog = await screen.findByRole('dialog', { name: 'Delete item' });
    expect(within(dialog).getByText('Are you sure you want to delete “Tent rental”?')).toBeTruthy();
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE' && r.path === '/admin-api/expenses/1')).toBe(true));
    expect(await screen.findByText('Item deleted.')).toBeTruthy();
  });

  it('shows a load error with a retry', async () => {
    let calls = 0;
    stubExpenses((r) => {
      if (r.path !== '/admin-api/expenses') return undefined;
      calls += 1;
      return calls === 1 ? jsonResponse(500, {}) : jsonResponse(200, LEDGER);
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/expenses?show_id=4');
    expect(await screen.findByText('The expenses could not be loaded.')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await within(await lines()).findByText('Tent rental')).toBeTruthy();
  });
});

describe('expense helpers', () => {
  it('formats amounts without the sign', () => {
    expect(formatAmount('-1234.5')).toBe('$1,234.50');
    expect(formatAmount('0.00')).toBe('$0.00');
  });

  it('maps a line to the form and back', () => {
    expect(toForm(null)).toEqual({ description: '', amount: null, type: 'expense' });
    expect(toForm(line({ amount: '-5.00', absolute_amount: '5.00', type: 'income' }))).toEqual({ description: 'Tent rental', amount: '5.00', type: 'income' });
    expect(toInput({ description: ' Food ', amount: '5.00', type: 'expense' })).toEqual({ description: 'Food', amount: '5.00', type: 'expense' });
  });
});
