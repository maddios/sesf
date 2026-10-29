import { describe, expect, it } from 'vitest';
import { buildSignPayload, firstValidationError, type SignerInput } from './form';

const adult: SignerInput = {
    firstName: ' Sam ',
    lastName: 'Signer',
    email: 'sam@example.test ',
    isMinor: false,
    guardianFirstName: '',
    guardianLastName: '',
};
const DATA_URL = 'data:image/png;base64,AAAA';

describe('firstValidationError', () => {
    it('accepts a complete adult form', () => {
        expect(firstValidationError(adult)).toBeNull();
    });

    it('treats the email as optional, but validates it when given', () => {
        expect(firstValidationError({ ...adult, email: '' })).toBeNull();
        expect(firstValidationError({ ...adult, email: 'not-an-email' })).toBe('Enter a valid email address.');
    });

    it('rejects a missing name', () => {
        expect(firstValidationError({ ...adult, lastName: '  ' })).toBe('First and last name are required.');
    });

    it('uses the legacy guardian message', () => {
        expect(firstValidationError({ ...adult, isMinor: true })).toBe('Enter parent or guardian first and last name.');
    });
});

describe('buildSignPayload', () => {
    it('sends trimmed draw fields with the waiver ui mode', () => {
        expect(buildSignPayload(adult, 'draw', DATA_URL, '', 'standalone')).toEqual({
            first_name: 'Sam',
            last_name: 'Signer',
            email: 'sam@example.test',
            signature_mode: 'draw',
            signature_base64: DATA_URL,
            waiver_ui_mode: 'standalone',
        });
    });

    it('sends an empty email when none was given', () => {
        expect(buildSignPayload({ ...adult, email: '' }, 'draw', DATA_URL, '', 'standalone').email).toBe('');
    });

    it('adds the typed name in type mode', () => {
        expect(buildSignPayload(adult, 'type', DATA_URL, ' Sam Signer ', 'standalone')).toMatchObject({
            signature_mode: 'type',
            signature_text: 'Sam Signer',
        });
    });

    it('adds the guardian fields only for a minor', () => {
        const payload = buildSignPayload({ ...adult, isMinor: true, guardianFirstName: 'Pat', guardianLastName: 'Parent' }, 'draw', DATA_URL, '', 'standalone');
        expect(payload).toMatchObject({ signing_for_minor: '1', adult_first_name: 'Pat', adult_last_name: 'Parent' });
    });
});
