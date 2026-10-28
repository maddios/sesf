/** Legacy typed signature: Allura script, 600x180 PNG, font shrinks from 88px to fit (legacy renderTypedSignature). */
export const TYPED_FONT = 'Allura';
export const TYPED_CANVAS = { width: 600, height: 180 } as const;
const MAX_FONT = 88;
const MIN_FONT = 28;
const FONT_SAFETY_MS = 4000;

/** The largest font size (step 2) at which the measured text fits maxWidth, never below MIN_FONT. */
export function fitFontSize(maxWidth: number, measure: (fontSize: number) => number): number {
    let size = MAX_FONT;
    while (size > MIN_FONT && measure(size) > maxWidth) {
        size -= 2;
    }

    return size;
}

function paint(ctx: CanvasRenderingContext2D, text: string): void {
    const { width, height } = TYPED_CANVAS;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, width, height);
    ctx.fillStyle = '#1a1a1a';
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'center';
    const measure = (size: number): number => {
        ctx.font = `${size}px ${TYPED_FONT}, cursive`;

        return ctx.measureText(text).width;
    };
    ctx.font = `${fitFontSize(width - 48, measure)}px ${TYPED_FONT}, cursive`;
    ctx.fillText(text, width / 2, height / 2);
}

/** PNG data URL of the typed name, or null when the text is blank. Waits for the font, with a 4 s fallback. */
export function renderTypedSignature(text: string): Promise<string | null> {
    const trimmed = text.trim();
    if (trimmed === '') {
        return Promise.resolve(null);
    }
    const canvas = document.createElement('canvas');
    canvas.width = TYPED_CANVAS.width;
    canvas.height = TYPED_CANVAS.height;
    const ctx = canvas.getContext('2d');
    if (ctx === null) {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        let finished = false;
        const draw = (): void => {
            if (finished) {
                return;
            }
            finished = true;
            paint(ctx, trimmed);
            resolve(canvas.toDataURL('image/png'));
        };
        if (typeof document.fonts === 'undefined') {
            draw();
            return;
        }
        const safety = window.setTimeout(draw, FONT_SAFETY_MS);
        document.fonts.load(`${MAX_FONT}px ${TYPED_FONT}`).then(
            () => {
                window.clearTimeout(safety);
                draw();
            },
            () => {
                window.clearTimeout(safety);
                draw();
            },
        );
    });
}
