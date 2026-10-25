import { describe, it, expect } from 'vitest';
import { DEFAULT_PARAMS, parsePositiveInt, paramsFromQuery, queryFromParams } from '../directoryQuery';

describe('directory query helpers', () => {
  it('reads known keys, trims text and falls back on bad pages', () => {
    expect(paramsFromQuery({ show_id: ' 36 ', name: ' Kai ', body_id: '2', page: '3' })).toEqual({
      show_id: '36',
      name: 'Kai',
      body_id: '2',
      page: 3,
    });
    expect(paramsFromQuery({})).toEqual(DEFAULT_PARAMS);
    expect(paramsFromQuery({ page: ['4', '9'] }).page).toBe(4);
    expect(paramsFromQuery({ page: '0' }).page).toBe(1);
    expect(paramsFromQuery({ page: 'abc' }).page).toBe(1);
    expect(paramsFromQuery({ name: null }).name).toBe('');
  });

  it('writes only the keys that differ from the defaults', () => {
    expect(queryFromParams(DEFAULT_PARAMS)).toEqual({});
    expect(queryFromParams({ show_id: '36', name: '', body_id: '', page: 2 })).toEqual({ show_id: '36', page: '2' });
    expect(queryFromParams({ show_id: '', name: 'Sh', body_id: '2', page: 1 })).toEqual({ name: 'Sh', body_id: '2' });
  });

  it('parses only positive safe integers', () => {
    expect(parsePositiveInt('7', 1)).toBe(7);
    expect(parsePositiveInt('-1', 1)).toBe(1);
    expect(parsePositiveInt('1.5', 1)).toBe(1);
    expect(parsePositiveInt('99999999999999999999', 1)).toBe(1);
    expect(parsePositiveInt(undefined, 5)).toBe(5);
  });
});
