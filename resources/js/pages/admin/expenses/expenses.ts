import { api } from '../../../api/client';
import { ADMIN_API_BASE, useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminField, FormValues } from '../../../components/admin/types';

export type ExpenseType = 'expense' | 'income';

/** One line as admin-api/expenses returns it (app/Http/Resources/Admin/ExpenseResource.php). */
export interface Expense {
  id: number;
  show_id: number | null;
  description: string;
  /** Stored value: positive = expense, negative = income (legacy sign). */
  amount: string;
  absolute_amount: string;
  type: ExpenseType;
  created_at: string | null;
  updated_at: string | null;
}

/** Legacy ExpenseTracker totals, as decimal strings. gross = net_received - total_expenses. */
export interface ExpenseTotals {
  registration_fees: string;
  net_received: string;
  total_expenses: string;
  gross: string;
}

export interface ExpenseLedger {
  data: Expense[];
  totals: ExpenseTotals;
}

export interface ExpenseInput {
  [key: string]: unknown;
  show_id?: number;
  description: string;
  amount: string;
  type: ExpenseType;
}

export const expensesApi = useAdminResource<Expense, ExpenseInput>('expenses');

export function fetchLedger(showId: number): Promise<ExpenseLedger> {
  return api.get<ExpenseLedger>(`${ADMIN_API_BASE}/expenses?show_id=${showId}`);
}

export const TYPE_OPTIONS = [
  { value: 'expense', label: 'Expense' },
  { value: 'income', label: 'Income' },
];

export const EXPENSE_FIELDS: AdminField[] = [
  { name: 'description', label: 'Description', maxlength: 255 },
  { name: 'amount', label: 'Amount', type: 'money', required: true },
  { name: 'type', label: 'Type', type: 'select', required: true, options: TYPE_OPTIONS },
];

export function toForm(expense: Expense | null): FormValues {
  return {
    description: expense?.description ?? '',
    amount: expense?.absolute_amount ?? null,
    type: expense?.type ?? 'expense',
  };
}

export function toInput(values: FormValues): ExpenseInput {
  return {
    description: typeof values.description === 'string' ? values.description.trim() : '',
    amount: typeof values.amount === 'string' ? values.amount : '',
    type: values.type === 'income' ? 'income' : 'expense',
  };
}

/** "-1234.5" -> "$1,234.50" (sign dropped: the page shows expenses in parentheses). */
export function formatAmount(decimal: string): string {
  const [whole = '0', fraction = '00'] = decimal.replace(/^-/, '').split('.');
  return `$${Number(whole).toLocaleString('en-US')}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}

export function isNegative(decimal: string): boolean {
  return decimal.startsWith('-') && /[1-9]/.test(decimal);
}

export function isPositive(decimal: string): boolean {
  return !decimal.startsWith('-') && /[1-9]/.test(decimal);
}
