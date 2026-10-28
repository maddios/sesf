import { byId, showAlert } from './dom';
import { requestJson } from './http';
import { clearPending } from './recovery';

interface ThankYou {
    show: { name: string };
    eventUrl: string;
    reference: string;
}

function isThankYou(body: unknown): body is ThankYou {
    if (typeof body !== 'object' || body === null) {
        return false;
    }
    const { show, eventUrl, reference } = body as Record<string, unknown>;

    return typeof show === 'object' && show !== null && typeof (show as { name?: unknown }).name === 'string'
        && typeof eventUrl === 'string' && typeof reference === 'string';
}

/** In an iframe (the portal's sign modal) the parent refreshes its list on this message (legacy). */
export const PORTAL_SIGNED_MESSAGE = 'sesf-portal-waiver-signed';

export async function mountThankYouPage(doc: Document, base: string): Promise<void> {
    clearPending();
    const loading = byId<HTMLElement>(doc, 'sesf-thankyou-loading');
    const error = byId<HTMLElement>(doc, 'sesf-thankyou-error');
    const result = await requestJson('GET', `${base}/data`);
    loading.hidden = true;

    if (!result.ok || !isThankYou(result.body)) {
        showAlert(error, result.status === 404
            ? 'We could not find a signed waiver for this link. Ask the event organizers if you need help.'
            : 'We could not load your confirmation. Refresh this page to try again.', 'error');
        return;
    }

    byId<HTMLElement>(doc, 'sesf-thankyou-show').textContent = result.body.show.name;
    byId<HTMLElement>(doc, 'sesf-thankyou-ref').textContent = `Confirmation reference (last 8 characters): ${result.body.reference}`;
    byId<HTMLAnchorElement>(doc, 'sesf-thankyou-event').href = result.body.eventUrl;
    byId<HTMLElement>(doc, 'sesf-thankyou').hidden = false;

    if (window.parent !== window) {
        byId<HTMLElement>(doc, 'sesf-thankyou-standalone-links').hidden = true;
        byId<HTMLElement>(doc, 'sesf-thankyou-portal-embed').hidden = false;
        window.parent.postMessage({ type: PORTAL_SIGNED_MESSAGE }, window.location.origin);
    }
}
