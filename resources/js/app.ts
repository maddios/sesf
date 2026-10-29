import { createSesfApp } from './bootstrap';
import { reloadForStaleChunk } from './staleAssets';

window.addEventListener('vite:preloadError', (event) => {
  const { payload } = event as Event & { payload?: unknown };
  reloadForStaleChunk(payload);
});

createSesfApp().app.mount('#app');
