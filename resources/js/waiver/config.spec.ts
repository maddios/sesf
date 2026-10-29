import { describe, expect, it } from 'vitest';
import { parseConfig } from './config';

describe('parseConfig', () => {
    it('returns the page for a valid config', () => {
        expect(parseConfig('{"page":"sign"}')).toEqual({ page: 'sign' });
        expect(parseConfig('{"page":"complete"}')).toEqual({ page: 'complete' });
    });

    it('rejects a missing, malformed or unknown config', () => {
        expect(parseConfig(null)).toBeNull();
        expect(parseConfig('{not json')).toBeNull();
        expect(parseConfig('{"page":"admin"}')).toBeNull();
    });
});
