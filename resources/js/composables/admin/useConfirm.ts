import { readonly, shallowRef } from 'vue';

export interface ConfirmOptions {
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Destructive action: danger-styled confirm button, Cancel focused first. */
  danger?: boolean;
  /** The user must type this text before the confirm button enables (for irreversible actions). */
  typedConfirmation?: string;
}

interface PendingConfirm {
  options: ConfirmOptions;
  resolve: (confirmed: boolean) => void;
}

const pending = shallowRef<PendingConfirm | null>(null);

function settle(confirmed: boolean): void {
  const current = pending.value;
  pending.value = null;
  current?.resolve(confirmed);
}

/** Opens the shared confirm dialog and resolves true (confirmed) or false. A newer request cancels an older one. */
function confirm(options: ConfirmOptions): Promise<boolean> {
  if (pending.value !== null) {
    settle(false);
  }
  return new Promise<boolean>((resolve) => {
    pending.value = { options, resolve };
  });
}

/**
 * Promise-based confirmation: `if (await confirm({ title, message, danger: true })) { ... }`.
 * The dialog itself is AdminConfirmHost (mounted once in AdminLayout), built on ConfirmDialog.
 */
export function useConfirm() {
  return { confirm, settle, pending: readonly(pending) };
}
