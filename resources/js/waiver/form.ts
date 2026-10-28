export type SignatureMode = 'draw' | 'type';

export interface SignerInput {
    firstName: string;
    lastName: string;
    email: string;
    isMinor: boolean;
    guardianFirstName: string;
    guardianLastName: string;
}

export interface SignPayload {
    first_name: string;
    last_name: string;
    email: string;
    signature_mode: SignatureMode;
    signature_base64: string;
    signature_text?: string;
    signing_for_minor?: '1';
    adult_first_name?: string;
    adult_last_name?: string;
    waiver_ui_mode: 'standalone' | 'embedded';
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** The first message to show for an incomplete form (names, optional email, guardian), or null. */
export function firstValidationError(input: SignerInput): string | null {
    if (input.firstName.trim() === '' || input.lastName.trim() === '') {
        return 'First and last name are required.';
    }
    if (input.email.trim() !== '' && !EMAIL_PATTERN.test(input.email.trim())) {
        return 'Enter a valid email address.';
    }
    if (input.isMinor && (input.guardianFirstName.trim() === '' || input.guardianLastName.trim() === '')) {
        return 'Enter parent or guardian first and last name.';
    }

    return null;
}

export function buildSignPayload(
    input: SignerInput,
    mode: SignatureMode,
    signatureDataUrl: string,
    typedText: string,
    waiverUiMode: 'standalone' | 'embedded',
): SignPayload {
    const payload: SignPayload = {
        first_name: input.firstName.trim(),
        last_name: input.lastName.trim(),
        email: input.email.trim(),
        signature_mode: mode,
        signature_base64: signatureDataUrl,
        waiver_ui_mode: waiverUiMode,
    };
    if (mode === 'type') {
        payload.signature_text = typedText.trim();
    }
    if (!input.isMinor) {
        return payload;
    }

    return {
        ...payload,
        signing_for_minor: '1',
        adult_first_name: input.guardianFirstName.trim(),
        adult_last_name: input.guardianLastName.trim(),
    };
}
