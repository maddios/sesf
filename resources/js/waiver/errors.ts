import type { ApiResult } from './http';

export interface Failure {
    message: string;
    /** 409: the waiver is already on file, so the widget stops offering submit. */
    completed: boolean;
}

export const UNEXPECTED_RESPONSE =
    'The server returned an unexpected response. Refresh the page. If you already tapped Submit, open the waiver portal and check for Signed before trying again.';

function serverMessage(body: unknown): string | null {
    if (typeof body !== 'object' || body === null) {
        return null;
    }
    const error = (body as { error?: unknown }).error;

    return typeof error === 'string' && error !== '' ? error : null;
}

export function describeSignFailure(result: ApiResult): Failure {
    const fromServer = serverMessage(result.body);
    switch (result.status) {
        case 409:
            return {
                message: fromServer ?? 'This waiver is already on file—you do not need to sign again. Refresh the waiver portal to see your status.',
                completed: true,
            };
        case 400:
            return {
                message: fromServer ?? 'This page may have expired. Refresh the page, then sign and submit again.',
                completed: false,
            };
        case 429:
            return {
                message: 'Too many submit attempts from this network. Wait about one minute, refresh the page, then try once more.',
                completed: false,
            };
        default:
            return {
                message: fromServer ?? (result.status >= 500
                    ? 'Server error. Wait a moment, refresh this page, and tap Submit once more. If it still fails, contact the event organizers.'
                    : 'Request failed. Check your connection, refresh if needed, and tap Submit again.'),
                completed: false,
            };
    }
}

/** Boot message when the signing details cannot be loaded (legacy showBootErr). */
export function describePrefillFailure(result: ApiResult): string {
    return serverMessage(result.body) ?? 'Could not load waiver. Check your connection and refresh the page.';
}

/** Sign-in failure messages. The login endpoint sends a JSON array of messages on 401. */
export function describeLoginFailure(result: ApiResult): string {
    if (result.status === 401 && Array.isArray(result.body) && typeof result.body[0] === 'string') {
        return result.body[0];
    }
    if (result.status === 429) {
        return 'Too many sign-in attempts. Wait a few minutes, then try again.';
    }
    if (result.status === 0) {
        return 'Connection problem. Check your connection, then try again.';
    }

    return serverMessage(result.body) ?? 'Could not sign you in. Try again.';
}
