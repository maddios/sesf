/** The emailed sign-in code is six digits. Anything else typed or pasted is dropped. */
export const SIGNIN_CODE_LENGTH = 6;

export function sanitizeCode(value: string): string {
  return value.replace(/\D/g, '').slice(0, SIGNIN_CODE_LENGTH);
}
