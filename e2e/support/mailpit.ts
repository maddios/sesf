import { expect, request, type APIRequestContext } from '@playwright/test';

/** Mailpit REST API (https://mailpit.axllent.org/docs/api-v1/). The sandbox publishes it on 127.0.0.1:8025. */
export const MAILPIT_URL = process.env.E2E_MAILPIT_URL ?? 'http://localhost:8025';

export interface MailSummary {
  ID: string;
  Subject: string;
  To: { Address: string; Name: string }[];
  From: { Address: string; Name: string };
  Created: string;
}

export interface MailMessage extends MailSummary {
  HTML: string;
  Text: string;
}

async function api(): Promise<APIRequestContext> {
  return request.newContext({ baseURL: MAILPIT_URL });
}

/** Messages to `to` (newest first), optionally filtered by subject. */
export async function messagesTo(to: string, subject?: RegExp): Promise<MailSummary[]> {
  const ctx = await api();
  try {
    const res = await ctx.get('/api/v1/search', { params: { query: `to:"${to}"`, limit: 50 } });
    expect(res.ok(), `Mailpit search failed: ${res.status()}`).toBeTruthy();
    const body = (await res.json()) as { messages: MailSummary[] };
    return body.messages.filter((m) => subject === undefined || subject.test(m.Subject));
  } finally {
    await ctx.dispose();
  }
}

export async function message(id: string): Promise<MailMessage> {
  const ctx = await api();
  try {
    const res = await ctx.get(`/api/v1/message/${id}`);
    expect(res.ok()).toBeTruthy();
    return (await res.json()) as MailMessage;
  } finally {
    await ctx.dispose();
  }
}

/**
 * Waits for a mail to `to` whose subject matches, that was not in `exclude` (ids seen before the action).
 * Returns the full message.
 */
export async function waitForMail(to: string, subject: RegExp, exclude: string[] = [], timeoutMs = 15_000): Promise<MailMessage> {
  let found: MailSummary | undefined;
  await expect
    .poll(
      async () => {
        found = (await messagesTo(to, subject)).find((m) => !exclude.includes(m.ID));
        return found !== undefined;
      },
      { timeout: timeoutMs, message: `mail to ${to} matching ${subject}` },
    )
    .toBe(true);
  return message(found!.ID);
}

/** Ids of the mails currently addressed to `to`, to exclude older mail from a later wait. */
export async function mailIds(to: string): Promise<string[]> {
  return (await messagesTo(to)).map((m) => m.ID);
}

/** Every http(s) link in a mail body. */
export function links(mail: MailMessage): string[] {
  const html = [...mail.HTML.matchAll(/href="([^"]+)"/g)].map((m) => m[1].replaceAll('&amp;', '&'));
  const text = [...mail.Text.matchAll(/https?:\/\/[^\s<>"]+/g)].map((m) => m[0]);
  return [...new Set([...html, ...text])].filter((l) => /^https?:/.test(l));
}
