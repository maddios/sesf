import type { PDFDocumentLoadingTask } from 'pdfjs-dist';

export interface PdfPage {
  getViewport(options: { scale: number }): { width: number; height: number };
  render(options: { canvas: HTMLCanvasElement; viewport: { width: number; height: number } }): { promise: Promise<void> };
}

export interface PdfDocument {
  numPages: number;
  getPage(number: number): Promise<PdfPage>;
  destroy(): Promise<void>;
}

export interface PdfLoader {
  open(url: string): Promise<PdfDocument>;
}

/** Loads the legacy pdf.js build (runs on older mobile browsers) and its worker on first use, so the library stays out of every bundle except the schedule tab's. */
export async function loadPdfjs(): Promise<PdfLoader> {
  const [pdfjs, worker] = await Promise.all([import('pdfjs-dist/legacy/build/pdf.mjs'), import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url')]);
  pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
  return {
    open: (url) => {
      const task: PDFDocumentLoadingTask = pdfjs.getDocument({ url });
      return task.promise.then((doc) => ({
        numPages: doc.numPages,
        getPage: (number) => doc.getPage(number) as unknown as Promise<PdfPage>,
        destroy: () => task.destroy(),
      }));
    },
  };
}
