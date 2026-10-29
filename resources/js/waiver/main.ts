import './waiver.css';
import { parseConfig } from './config';
import { mountPortalPage } from './portal-page';
import { mountSharePage } from './share-page';
import { mountSignPage } from './sign-page';
import { mountThankYouPage } from './thank-you-page';

const config = parseConfig(document.getElementById('sesf-waiver-config')?.textContent ?? null);
const base = window.location.pathname.replace(/\/+$/, '');

if (config !== null) {
    switch (config.page) {
        case 'sign':
            mountSignPage(document, base);
            break;
        case 'thank-you':
            void mountThankYouPage(document, base);
            break;
        case 'portal':
            mountPortalPage(document, base);
            break;
        case 'share':
            mountSharePage(document);
            break;
        case 'not-found':
            break;
    }
}
