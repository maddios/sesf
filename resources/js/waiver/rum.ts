interface RumApi {
    addError?: (error: Error, context?: Record<string, string>) => void;
}

/** Reports a widget error to Datadog RUM when the shell has loaded it (legacy sesfRumReport). No-op otherwise. */
export function reportToRum(message: string, context: Record<string, string>): void {
    const rum = (window as { DD_RUM?: RumApi }).DD_RUM;
    if (typeof rum?.addError === 'function') {
        rum.addError(new Error(message), context);
    }
}
