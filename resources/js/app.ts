import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { createAppRouter } from './router';
import { setOnUnauthorized } from './api/client';
import { useAuthStore } from './stores/auth';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia);

// A 401 from any API call logs the user out in the auth store.
setOnUnauthorized(() => useAuthStore(pinia).clear());

app.use(createAppRouter()).mount('#app');
