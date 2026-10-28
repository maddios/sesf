import { api } from '../../../api/client';
import { ADMIN_API_BASE } from '../../../composables/admin/useAdminResource';

/** One shirt type with its counts (app/Services/Admin/ShirtInventory.php). Money is integer cents. */
export interface ShirtTypeRow {
  id: number;
  name: string;
  price_cents: number | null;
  stock: number;
  ordered: number;
  handed_out: number;
  adhoc_sold: number;
  remaining: number;
  available_for_adhoc: number;
}

export interface ShirtLine {
  id: number;
  type_id: number;
  type_name: string;
  handed_out: boolean;
}

export interface ShirtRegistrationRow {
  id: number;
  invoice_id: number;
  primary_name: string;
  lines: ShirtLine[];
  left_to_hand_out: number;
  all_handed_out: boolean;
}

export interface AdhocSaleRow {
  id: number;
  created_at: string | null;
  sold_by: string;
  items: Array<{ type_id: number; type_name: string; count: number }>;
}

export interface ShirtInventory {
  show: { id: number; name: string };
  types: ShirtTypeRow[];
  registrations: ShirtRegistrationRow[];
  adhoc_sales: AdhocSaleRow[];
}

const BASE = `${ADMIN_API_BASE}/shirts`;

export const shirtsApi = {
  async inventory(showId: number): Promise<ShirtInventory> {
    return (await api.get<{ data: ShirtInventory }>(`${BASE}/inventory?show_id=${showId}`)).data;
  },
  async setStock(showId: number, stock: Record<number, number>): Promise<ShirtInventory> {
    return (await api.put<{ data: ShirtInventory }>(`${BASE}/inventory/${showId}/stock`, { stock })).data;
  },
  async setHandedOut(line: ShirtLine, handedOut: boolean): Promise<void> {
    await api.post<unknown>(`${BASE}/${line.id}/handed-out`, { handed_out: handedOut });
  },
  async recordSale(showId: number, typeIds: number[]): Promise<ShirtInventory> {
    return (await api.post<{ data: ShirtInventory }>(`${BASE}/inventory/${showId}/sales`, { type_ids: typeIds })).data;
  },
  async deleteSale(sale: AdhocSaleRow): Promise<void> {
    await api.delete<unknown>(`${BASE}/sales/${sale.id}`);
  },
};

/** Shirt type ids for a sale: each type repeated by its count. */
export function saleLines(counts: Record<number, number>): number[] {
  return Object.entries(counts).flatMap(([typeId, count]) => Array.from({ length: count }, () => Number(typeId)));
}

/** Search across the invoice number and the primary registrant's name. */
export function matchesRegistration(row: ShirtRegistrationRow, query: string): boolean {
  const terms = query.trim().toLowerCase().split(/\s+/).filter((term) => term !== '');
  if (terms.length === 0) return true;
  const haystack = `#${row.invoice_id} ${row.invoice_id} ${row.primary_name}`.toLowerCase();
  return terms.every((term) => haystack.includes(term.replace(/^#/, '')));
}

export function formatCents(cents: number | null): string {
  return cents === null ? '' : `$${(cents / 100).toFixed(2)}`;
}

export interface Shortfall {
  name: string;
  committed: number;
  stock: number;
  short: number;
}

/** Legacy shortfall warning: ordered plus ad hoc sold more than the stock on hand. */
export function shortfalls(types: ShirtTypeRow[]): Shortfall[] {
  return types
    .map((type) => ({ name: type.name, committed: type.ordered + type.adhoc_sold, stock: type.stock }))
    .filter((item) => item.committed > item.stock)
    .map((item) => ({ ...item, short: item.committed - item.stock }));
}

/** Hand-out status of one registration, as the legacy Status column words it. */
export function handOutStatus(row: ShirtRegistrationRow): { label: 'All handed out' | 'Partial' | 'Pending'; left: number } {
  if (row.all_handed_out) return { label: 'All handed out', left: 0 };
  const total = row.lines.length;
  return { label: row.left_to_hand_out < total && row.left_to_hand_out > 0 ? 'Partial' : 'Pending', left: row.left_to_hand_out };
}
