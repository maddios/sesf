import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import InvoiceDocument from '../InvoiceDocument.vue';
import InvoiceStatusBadge from '../InvoiceStatusBadge.vue';
import { parseInvoice } from '../../../api/billing';
import { invoiceBody } from '../../../test/billing';

const render1 = (body: Record<string, unknown>) => render(InvoiceDocument, { props: { invoice: parseInvoice(body) } });

describe('InvoiceDocument', () => {
  it('shows the event, invoice number and every kind of line item', () => {
    render1(invoiceBody());
    expect(screen.getByRole('heading', { level: 2, name: 'Southeast Sharkfest 2026' })).toBeTruthy();
    expect(screen.getByText('Invoice no. 1042')).toBeTruthy();
    const table = screen.getByRole('table', { name: 'Invoice line items' });
    for (const label of ['Car registration', 'Guest', 'Shirt: Large', 'Dinner', 'Donation']) {
      expect(within(table).getByRole('rowheader', { name: label })).toBeTruthy();
    }
    const dinner = within(table).getByRole('row', { name: /Dinner/ });
    expect(within(dinner).getByText('2')).toBeTruthy();
    expect(within(dinner).getByText('$30.00')).toBeTruthy();
    expect(within(dinner).getByText('$60.00')).toBeTruthy();
  });

  it('marks the column headers with scope', () => {
    render1(invoiceBody());
    const headers = screen.getAllByRole('columnheader');
    expect(headers.map((h) => h.textContent?.trim())).toEqual(['Item', 'Quantity', 'Unit price', 'Amount']);
    for (const header of headers) {
      expect(header.getAttribute('scope')).toBe('col');
    }
  });

  it('shows the total, discount with its note, received and the balance due with separators', () => {
    render1(invoiceBody());
    expect(screen.getByText('$1,110.00')).toBeTruthy();
    expect(screen.getByText('Early bird')).toBeTruthy();
    expect(screen.getByText('-$10.00')).toBeTruthy();
    expect(screen.getByText('$100.00')).toBeTruthy();
    expect(screen.getAllByText('$1,000.00').length).toBeGreaterThan(0);
    expect(screen.getByText('Balance due')).toBeTruthy();
    expect(screen.getByText('Unpaid')).toBeTruthy();
  });

  it('shows a credit, not a negative balance', () => {
    render1(invoiceBody({}, { owed: '-250.50', discount: '0.00', received: '300.00' }));
    expect(screen.getByText('Credit $250.50')).toBeTruthy();
    expect(screen.getByText('Credit')).toBeTruthy();
    expect(screen.queryByText(/-\$250/)).toBeNull();
  });

  it('hides the discount row when there is none, and shows the donation row only without a donation line', () => {
    const { unmount } = render1(invoiceBody({}, { discount: '0.00', owed: '0.00', received: '1110.00' }));
    expect(screen.queryByText('Discount')).toBeNull();
    expect(screen.getByText('Paid')).toBeTruthy();
    unmount();

    const withoutDonationLine = invoiceBody({ donation: 5 }, { lineItems: [{ kind: 'car', label: 'Car', quantity: 1, unit: '1.00', amount: '1.00' }], donation: '5.00' });
    render1(withoutDonationLine);
    expect(screen.getByText('Donation')).toBeTruthy();
  });

  it('shows an empty-items message when the server sends no line items', () => {
    render1(invoiceBody({}, { lineItems: [] }));
    expect(screen.getByText('No items on this invoice.')).toBeTruthy();
  });

  it('uses a generic title when the event is unknown', () => {
    render1(invoiceBody({ car_show: null }));
    expect(screen.getByRole('heading', { level: 2, name: 'Registration' })).toBeTruthy();
  });

  it('has no accessibility violations', async () => {
    const { container } = render1(invoiceBody());
    expect(await axe(container, { rules: { region: { enabled: false } } })).toHaveNoViolations();
  });
});

describe('InvoiceStatusBadge', () => {
  it('names the status in text and hides the marker from assistive tech', () => {
    const { container } = render(InvoiceStatusBadge, { props: { status: 'cancelled' } });
    expect(screen.getByText('Cancelled')).toBeTruthy();
    expect(container.querySelector('[data-status="cancelled"]')).not.toBeNull();
    expect(container.querySelector('[aria-hidden="true"]')?.textContent).toBe('✕');
  });

  it.each([
    ['paid', 'Paid'],
    ['unpaid', 'Unpaid'],
    ['credit', 'Credit'],
  ] as const)('labels %s', (status, label) => {
    render(InvoiceStatusBadge, { props: { status } });
    expect(screen.getByText(label)).toBeTruthy();
  });
});
