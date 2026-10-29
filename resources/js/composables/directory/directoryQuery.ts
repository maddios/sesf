import type { LocationQuery, LocationQueryRaw } from 'vue-router';

/** Filter and page values as they appear in the hash query string. Empty string means "not set". */
export interface DirectoryParams {
  show_id: string;
  name: string;
  body_id: string;
  page: number;
}

export const DEFAULT_PARAMS: DirectoryParams = { show_id: '', name: '', body_id: '', page: 1 };

function firstValue(value: LocationQuery[string]): string | undefined {
  const raw = Array.isArray(value) ? value[0] : value;
  return typeof raw === 'string' ? raw : undefined;
}

/** Positive integer from a query value, or the fallback. */
export function parsePositiveInt(value: string | undefined, fallback: number): number {
  if (value === undefined || !/^\d+$/.test(value)) {
    return fallback;
  }
  const n = Number(value);
  return Number.isSafeInteger(n) && n >= 1 ? n : fallback;
}

/** Reads ?show_id=&name=&body_id=&page= ; unknown or malformed values fall back to defaults. */
export function paramsFromQuery(query: LocationQuery): DirectoryParams {
  return {
    show_id: firstValue(query.show_id)?.trim() ?? '',
    name: firstValue(query.name)?.trim() ?? '',
    body_id: firstValue(query.body_id)?.trim() ?? '',
    page: parsePositiveInt(firstValue(query.page), 1),
  };
}

/** Writes only the keys that differ from the defaults, so a clean list has a clean URL. */
export function queryFromParams(params: DirectoryParams): LocationQueryRaw {
  const query: LocationQueryRaw = {};
  if (params.show_id !== '') {
    query.show_id = params.show_id;
  }
  if (params.name !== '') {
    query.name = params.name;
  }
  if (params.body_id !== '') {
    query.body_id = params.body_id;
  }
  if (params.page !== 1) {
    query.page = String(params.page);
  }
  return query;
}
