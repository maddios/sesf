/** A signature was drawn or typed and the waiver has not been submitted (or is not being submitted). */
export function isSignatureDirty(completed: boolean, submitting: boolean, signatureDataUrl: string, typedText: string): boolean {
    return !completed && !submitting && (signatureDataUrl !== '' || typedText.trim() !== '');
}
