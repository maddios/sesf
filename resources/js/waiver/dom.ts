export type Tone = 'error' | 'success' | 'info';

export function byId<T extends HTMLElement>(doc: Document, id: string): T {
    const element = doc.getElementById(id);
    if (element === null) {
        throw new Error(`Waiver page is missing #${id}`);
    }

    return element as T;
}

/** Shows an alert with the given text, or hides it when the text is empty. Text is never parsed as HTML. */
export function showAlert(alert: HTMLElement, message: string, tone: Tone = 'info'): void {
    alert.textContent = message;
    alert.className = `sesf-waiver-alert sesf-waiver-alert-${tone}`;
    alert.hidden = message === '';
}
