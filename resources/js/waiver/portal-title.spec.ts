import { describe, expect, it } from 'vitest';
import { waiverTitle } from './portal-title';

describe('waiverTitle', () => {
    it('adds a possessive that matches the name', () => {
        expect(waiverTitle('Jane')).toBe("Jane's Waiver");
        expect(waiverTitle('James')).toBe("James' Waiver");
        expect(waiverTitle('')).toBe("Waiver's Waiver");
    });
});
