import { ApiError, api } from './client';

/** Body for POST /home/report. Anonymous senders must give name and email; signed-in users send subject and message only. */
export type ContactInput = {
  name?: string;
  email?: string;
  subject: string;
  message: string;
};

/**
 * Sends a comment or bug report. The endpoint answers 200 with an empty body, which the API client
 * reports as an ApiError; any 2xx is therefore treated as success here.
 */
export async function sendReport(input: ContactInput): Promise<void> {
  try {
    await api.post('/home/report', { ...input });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 200 && error.status < 300) {
      return;
    }
    throw error;
  }
}
