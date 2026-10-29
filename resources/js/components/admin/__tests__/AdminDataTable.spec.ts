import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { h } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import AdminDataTable from '../AdminDataTable.vue';
import type { AdminColumn } from '../types';
import type { AdminListState } from '../../../composables/admin/useAdminList';

interface Row {
  id: number;
  name: string;
  url: string | null;
}

const columns: AdminColumn<Row>[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'url', label: 'Website', format: (row) => row.url ?? 'none' },
  { key: 'id', label: 'Id', srOnlyLabel: true, hideOnMobile: true },
];

const state: AdminListState = { page: 1, perPage: 25, sort: 'name', direction: 'asc', search: '', filters: {} };

function renderTable(props: Record<string, unknown> = {}, slots: Record<string, unknown> = {}) {
  return render(AdminDataTable, {
    props: {
      label: 'Things',
      columns: columns as unknown as AdminColumn<object>[],
      rows: [
        { id: 1, name: 'Alpha', url: 'https://a.example' },
        { id: 2, name: 'Bravo', url: null },
      ],
      meta: { page: 1, per_page: 25, total: 2, last_page: 1 },
      state,
      debounceMs: 0,
      ...props,
    },
    slots,
    // BasePagination reads the router even when it does not sync the query.
    global: { plugins: [createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { render: () => null } }] })] },
  });
}

describe('AdminDataTable', () => {
  it('renders a labelled table with aria-sort and formatted cells, and passes axe', async () => {
    const { container } = renderTable();
    const table = screen.getByRole('table', { name: 'Things' });
    const rows = within(table).getAllByRole('row');
    expect(rows).toHaveLength(3);
    expect(within(rows[2]!).getByText('none')).toBeTruthy();
    const nameHeader = within(table).getByRole('columnheader', { name: /Name/ });
    expect(nameHeader.getAttribute('aria-sort')).toBe('ascending');
    expect(within(table).getByRole('columnheader', { name: 'Website' }).getAttribute('aria-sort')).toBeNull();
    expect(screen.getByText('1–2 of 2')).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('emits sort from the header button (keyboard too)', async () => {
    const user = userEvent.setup();
    const { emitted } = renderTable({ state: { ...state, direction: 'desc' } });
    const button = within(screen.getByRole('table')).getByRole('button', { name: /Name/ });
    expect(button.getAttribute('title')).toBe('Sort by Name, ascending');
    button.focus();
    await user.keyboard('{Enter}');
    expect(emitted().sort).toEqual([['name']]);
  });

  it('debounces search and searches immediately on Enter', async () => {
    const user = userEvent.setup();
    const { emitted } = renderTable({ debounceMs: 10_000 });
    const input = screen.getByRole('searchbox', { name: 'Search' });
    await user.type(input, ' acme {Enter}');
    expect(emitted().search).toEqual([['acme']]);
  });

  it('emits the search after the debounce', async () => {
    vi.useFakeTimers();
    const { emitted } = renderTable({ debounceMs: 300 });
    const input = screen.getByRole('searchbox', { name: 'Search' }) as HTMLInputElement;
    input.value = 'bolt';
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(299);
    expect(emitted().search).toBeUndefined();
    await vi.advanceTimersByTimeAsync(1);
    expect(emitted().search).toEqual([['bolt']]);
    vi.useRealTimers();
  });

  it('emits page and per-page changes', async () => {
    const user = userEvent.setup();
    const { emitted } = renderTable({ meta: { page: 1, per_page: 25, total: 60, last_page: 3 } });
    await user.click(screen.getByRole('button', { name: 'Page 2' }));
    await user.selectOptions(screen.getByLabelText('Per page'), '50');
    expect(emitted().page).toEqual([[2]]);
    expect(emitted().perPage).toEqual([[50]]);
  });

  it('shows loading (aria-busy), error with retry and the empty state', async () => {
    const user = userEvent.setup();
    const { emitted, rerender } = renderTable({ loading: true });
    expect(screen.getByRole('status').textContent).toContain('Loading');
    expect(document.querySelector('[aria-busy="true"]')).not.toBeNull();

    await rerender({ loading: false, error: 'The list could not be loaded.', rows: [] });
    expect(screen.getByRole('alert').textContent).toContain('could not be loaded');
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(emitted().retry).toHaveLength(1);

    await rerender({ error: null, rows: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } });
    expect(screen.getByText('No records match.')).toBeTruthy();
    expect(screen.getByText('0 records')).toBeTruthy();
  });

  it('renders cell and action slots in the table and in the mobile cards', () => {
    renderTable(
      {},
      {
        'cell-name': ({ row }: { row: Row }) => h('strong', `*${row.name}*`),
        actions: ({ row }: { row: Row }) => h('button', { type: 'button' }, `Edit ${row.name}`),
        filters: () => h('span', 'filter-slot'),
        'toolbar-end': () => h('button', { type: 'button' }, 'New'),
      },
    );
    expect(screen.getAllByText('*Alpha*')).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: 'Edit Bravo' })).toHaveLength(2);
    const cards = screen.getByRole('list', { name: 'Things' });
    expect(within(cards).getAllByRole('listitem')).toHaveLength(2);
    // hideOnMobile columns are not in the cards.
    expect(within(cards).queryByText('Id')).toBeNull();
    expect(screen.getByText('filter-slot')).toBeTruthy();
    expect(within(screen.getByRole('table')).getByRole('columnheader', { name: 'Actions' })).toBeTruthy();
  });

  it('keeps the search box in step with the state and can hide it', async () => {
    const { rerender } = renderTable();
    await rerender({ state: { ...state, search: 'from-url' } });
    expect((screen.getByRole('searchbox') as HTMLInputElement).value).toBe('from-url');
    await rerender({ searchable: false });
    expect(screen.queryByRole('searchbox')).toBeNull();
  });
});
