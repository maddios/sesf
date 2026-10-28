/** Shared types for the admin kit (components/admin). */

export type FormValues = Record<string, unknown>;

export type AdminFieldType = 'text' | 'textarea' | 'url' | 'email' | 'password' | 'number' | 'checkbox' | 'select' | 'money' | 'date' | 'datetime' | 'richtext';

/** One field in an AdminFormModal schema. `name` is the API field (errors are matched on it). */
export interface AdminField {
  name: string;
  label: string;
  type?: AdminFieldType;
  required?: boolean;
  /** Mirror the DB column width (the server enforces it too). On 'richtext' it counts the HTML and is never enforced by cutting. */
  maxlength?: number;
  hint?: string;
  rows?: number;
  options?: Array<{ value: string; label: string }>;
  /** Take the full form width on desktop (textareas and rich text always do). */
  wide?: boolean;
  /** Start a new titled group of fields before this one. */
  section?: string;
}

/** One AdminDataTable column. `key` is the row property and, when sortable, the server sort column. */
export interface AdminColumn<T> {
  key: string;
  label: string;
  sortable?: boolean;
  /** Text for the cell when no `cell-<key>` slot is given. Defaults to String(row[key]). */
  format?: (row: T) => string;
  align?: 'left' | 'right' | 'center';
  /** Header visually hidden (still read by screen readers), e.g. an image column. */
  srOnlyLabel?: boolean;
  /** Left out of the mobile card layout. */
  hideOnMobile?: boolean;
  class?: string;
}
