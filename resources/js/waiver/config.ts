export type WaiverPage = 'sign' | 'complete' | 'thank-you' | 'portal' | 'share' | 'not-found';

export interface WaiverConfig {
    page: WaiverPage;
}

const PAGES: readonly string[] = ['sign', 'complete', 'thank-you', 'portal', 'share', 'not-found'];

export function parseConfig(raw: string | null): WaiverConfig | null {
    if (raw === null) {
        return null;
    }

    let parsed: unknown;
    try {
        parsed = JSON.parse(raw);
    } catch {
        return null;
    }

    const page = typeof parsed === 'object' && parsed !== null ? (parsed as { page?: unknown }).page : undefined;

    return typeof page === 'string' && PAGES.includes(page) ? { page: page as WaiverPage } : null;
}
