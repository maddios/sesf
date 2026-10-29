import { EMAIL_PATTERN } from '../register/registerValidation';

/** Field limits for the comment/bug form. Subject and message limits are the app's own choice (the endpoint sets none). */
export const CONTACT_LIMITS = {
  name: 60,
  email: 60,
  subject: 120,
  message: 2000,
} as const;

export interface ContactValues {
  name: string;
  email: string;
  subject: string;
  message: string;
}

export type ContactErrors = Partial<Record<keyof ContactValues, string>>;

/** Anonymous senders must give a name and email. Signed-in users only need a subject and message. */
export function validateContact(values: ContactValues, anonymous: boolean): ContactErrors {
  const errors: ContactErrors = {};
  const name = values.name.trim();
  const email = values.email.trim();
  const subject = values.subject.trim();
  const message = values.message.trim();

  if (anonymous) {
    if (name === '') {
      errors.name = 'Enter your name.';
    } else if (name.length > CONTACT_LIMITS.name) {
      errors.name = `Name must be ${CONTACT_LIMITS.name} characters or fewer.`;
    }
    if (email === '') {
      errors.email = 'Enter your email address.';
    } else if (!EMAIL_PATTERN.test(email)) {
      errors.email = 'Enter a valid email address.';
    } else if (email.length > CONTACT_LIMITS.email) {
      errors.email = `Email must be ${CONTACT_LIMITS.email} characters or fewer.`;
    }
  }

  if (subject === '') {
    errors.subject = 'Enter a subject.';
  } else if (subject.length > CONTACT_LIMITS.subject) {
    errors.subject = `Subject must be ${CONTACT_LIMITS.subject} characters or fewer.`;
  }

  if (message === '') {
    errors.message = 'Enter a message.';
  } else if (message.length > CONTACT_LIMITS.message) {
    errors.message = `Message must be ${CONTACT_LIMITS.message} characters or fewer.`;
  }

  return errors;
}
