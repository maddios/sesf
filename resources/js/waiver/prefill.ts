export interface Prefill {
    first: string;
    last: string;
    email: string;
}

/** The prefill endpoint body, or null when it does not have the expected shape. */
export function parsePrefill(body: unknown): Prefill | null {
    if (typeof body !== 'object' || body === null) {
        return null;
    }
    const { first, last, email } = body as Record<string, unknown>;

    return typeof first === 'string' && typeof last === 'string' && typeof email === 'string' ? { first, last, email } : null;
}
