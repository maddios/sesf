import { ref } from 'vue';
import { messagesFromError } from '../../api/account';

/**
 * Busy flag, banner messages and a success notice for one form. `run` sends the request: a success sets the
 * notice, a failure sets the server's validation messages (or a generic line for other failures).
 */
export function useSaveState(fallbackError = 'Something went wrong. Please try again.') {
  const busy = ref(false);
  const messages = ref<string[]>([]);
  const success = ref<string | null>(null);

  async function run(action: () => Promise<void>, successMessage: string): Promise<boolean> {
    busy.value = true;
    messages.value = [];
    success.value = null;
    try {
      await action();
      success.value = successMessage;
      return true;
    } catch (error) {
      const fromServer = messagesFromError(error);
      messages.value = fromServer.length > 0 ? fromServer : [fallbackError];
      return false;
    } finally {
      busy.value = false;
    }
  }

  function reset(): void {
    messages.value = [];
    success.value = null;
  }

  return { busy, messages, success, run, reset };
}
