export interface Point {
    /** Position relative to the canvas box (0..1), so a resize keeps the drawing in place. */
    x: number;
    y: number;
    /** Ink width in CSS pixels at this point. */
    width: number;
    time: number;
}

export type Stroke = Point[];

/** Legacy signature_pad options (v4): minWidth 0.8, maxWidth 2.5, throttle 16, penColor #111, white background. */
export const PEN = { minWidth: 0.8, maxWidth: 2.5, throttleMs: 16, color: '#111111', background: '#ffffff' } as const;

/** Matches the server's signature pixel cap (waivers.signature.max_width / max_height). */
export const SIGNATURE_MAX_WIDTH = 2000;
export const SIGNATURE_MAX_HEIGHT = 800;

export function hasInk(strokes: readonly Stroke[]): boolean {
    return strokes.some((stroke) => stroke.length > 0);
}

/** Backing-store size: device resolution, reduced so the PNG stays within the server's pixel cap. */
export function canvasPixelSize(cssWidth: number, cssHeight: number, devicePixelRatio: number): { width: number; height: number } {
    const scale = Math.min(devicePixelRatio, SIGNATURE_MAX_WIDTH / cssWidth, SIGNATURE_MAX_HEIGHT / cssHeight);

    return { width: Math.floor(cssWidth * scale), height: Math.floor(cssHeight * scale) };
}

/** Velocity-based ink width: slow strokes approach maxWidth, fast strokes minWidth, smoothed against the previous width. */
export function nextPenWidth(previous: number, distance: number, elapsedMs: number): number {
    const speed = elapsedMs > 0 ? distance / elapsedMs : 0;
    const target = Math.min(PEN.maxWidth, Math.max(PEN.minWidth, PEN.maxWidth - speed * 2));

    return 0.7 * target + 0.3 * previous;
}

/**
 * Pointer-event signature pad on a canvas. Output is a PNG data URL on a white background. Ink
 * width follows the stroke speed, as the legacy signature_pad did.
 */
export class SignaturePad {
    private readonly strokes: Stroke[] = [];
    private current: Stroke | null = null;
    private context: CanvasRenderingContext2D | null = null;
    private cssWidth = 0;
    private cssHeight = 0;

    constructor(private readonly canvas: HTMLCanvasElement) {
        canvas.addEventListener('pointerdown', (event) => this.start(event));
        canvas.addEventListener('pointermove', (event) => this.extend(event));
        canvas.addEventListener('pointerup', () => this.end());
        canvas.addEventListener('pointercancel', () => this.end());
        this.resize();
    }

    isEmpty(): boolean {
        return !hasInk(this.strokes);
    }

    clear(): void {
        this.strokes.length = 0;
        this.current = null;
        this.redraw();
    }

    toDataUrl(): string {
        return this.canvas.toDataURL('image/png');
    }

    /** Sizes the backing store to the displayed box and redraws. */
    resize(): void {
        const box = this.canvas.getBoundingClientRect();
        this.cssWidth = box.width;
        this.cssHeight = box.height;
        const size = canvasPixelSize(this.cssWidth, this.cssHeight, window.devicePixelRatio || 1);
        this.canvas.width = size.width;
        this.canvas.height = size.height;
        this.context = this.canvas.getContext('2d');
        this.redraw();
    }

    private start(event: PointerEvent): void {
        this.canvas.setPointerCapture(event.pointerId);
        const point = this.sample(event, PEN.maxWidth);
        this.current = [point];
        this.strokes.push(this.current);
        this.redraw();
    }

    private extend(event: PointerEvent): void {
        const stroke = this.current;
        const last = stroke?.[stroke.length - 1];
        if (stroke === null || last === undefined) {
            return;
        }
        const elapsed = event.timeStamp - last.time;
        if (elapsed < PEN.throttleMs) {
            return;
        }
        const next = this.sample(event, last.width);
        const distance = Math.hypot((next.x - last.x) * this.cssWidth, (next.y - last.y) * this.cssHeight);
        next.width = nextPenWidth(last.width, distance, elapsed);
        stroke.push(next);
        this.redraw();
    }

    private end(): void {
        this.current = null;
    }

    private sample(event: PointerEvent, width: number): Point {
        const box = this.canvas.getBoundingClientRect();

        return {
            x: (event.clientX - box.left) / box.width,
            y: (event.clientY - box.top) / box.height,
            width,
            time: event.timeStamp,
        };
    }

    private redraw(): void {
        const ctx = this.context;
        if (ctx === null) {
            return;
        }
        ctx.setTransform(this.canvas.width / this.cssWidth, 0, 0, this.canvas.height / this.cssHeight, 0, 0);
        ctx.fillStyle = PEN.background;
        ctx.fillRect(0, 0, this.cssWidth, this.cssHeight);
        ctx.strokeStyle = PEN.color;
        ctx.fillStyle = PEN.color;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        for (const stroke of this.strokes) {
            this.drawStroke(ctx, stroke);
        }
    }

    private drawStroke(ctx: CanvasRenderingContext2D, stroke: Stroke): void {
        for (let i = 0; i < stroke.length; i++) {
            const point = stroke[i];
            if (point === undefined) {
                continue;
            }
            const previous = stroke[i - 1];
            const x = point.x * this.cssWidth;
            const y = point.y * this.cssHeight;
            ctx.beginPath();
            if (previous === undefined) {
                ctx.arc(x, y, point.width / 2, 0, Math.PI * 2);
                ctx.fill();
                continue;
            }
            ctx.lineWidth = point.width;
            ctx.moveTo(previous.x * this.cssWidth, previous.y * this.cssHeight);
            ctx.lineTo(x, y);
            ctx.stroke();
        }
    }
}
