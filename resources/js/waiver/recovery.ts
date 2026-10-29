/** Legacy sessionStorage hints: a submit that was in flight, and a waiver that just completed on this device. */
const PENDING_KEY = 'sesf_waiver_submit_pending_v1';
const COMPLETED_KEY = 'sesf_waiver_completed_recent_v1';
const PENDING_MAX_AGE_MS = 15 * 60 * 1000;
const PENDING_HINT_AGE_MS = 25 * 1000;
const COMPLETED_MAX_AGE_MS = 5 * 60 * 1000;

interface Stored {
    postUrl: string;
    at: number;
    thankYouUrl?: string;
}

function read(key: string): Stored | null {
    try {
        const raw = sessionStorage.getItem(key);

        return raw === null ? null : (JSON.parse(raw) as Stored);
    } catch {
        return null;
    }
}

function write(key: string, value: Stored): void {
    try {
        sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
        return;
    }
}

function remove(key: string): void {
    try {
        sessionStorage.removeItem(key);
    } catch {
        return;
    }
}

export function markPending(postUrl: string, now: number): void {
    write(PENDING_KEY, { postUrl, at: now });
}

export function clearPending(): void {
    remove(PENDING_KEY);
}

export function markCompleted(postUrl: string, thankYouUrl: string, now: number): void {
    clearPending();
    write(COMPLETED_KEY, { postUrl, at: now, thankYouUrl });
}

/** The hint to show on load, or '' (legacy checkRecoveryHintsOnLoad). */
export function recoveryHint(postUrl: string, now: number): string {
    const recent = read(COMPLETED_KEY);
    if (recent !== null && recent.postUrl === postUrl && now - recent.at < COMPLETED_MAX_AGE_MS) {
        return 'You recently reached the thank-you screen from this browser. If you are signing for another person on this same device, continue below. Otherwise open the waiver portal to confirm everyone shows as Signed.';
    }
    if (recent !== null && now - recent.at >= COMPLETED_MAX_AGE_MS) {
        remove(COMPLETED_KEY);
    }

    const pending = read(PENDING_KEY);
    if (pending === null || pending.postUrl !== postUrl) {
        return '';
    }
    const age = now - pending.at;
    if (age > PENDING_MAX_AGE_MS) {
        remove(PENDING_KEY);

        return '';
    }

    return age > PENDING_HINT_AGE_MS
        ? 'If you already tapped Submit and the page stopped responding, your waiver may still have been saved. Open the waiver portal and look for a green Signed badge before signing again.'
        : '';
}
