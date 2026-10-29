/** Joins client and server messages for one control into the list FormField renders. */
export function messageList(...groups: (string | undefined | readonly string[])[]): string[] {
  return groups.flatMap((group) => (group === undefined ? [] : typeof group === 'string' ? [group] : [...group]));
}
