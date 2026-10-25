/** Text for ResponseBanner: one message, a list, or field-keyed lists (all shown, in order). */
export type BannerMessages = string | string[] | Record<string, string[]> | null | undefined;

/** Flattens BannerMessages into display strings. Blank entries are dropped. */
export function flattenMessages(messages: BannerMessages): string[] {
  if (messages === null || messages === undefined) {
    return [];
  }
  const list = typeof messages === 'string' ? [messages] : Array.isArray(messages) ? messages : Object.values(messages).flat();
  return list.filter((item) => item.trim() !== '');
}
