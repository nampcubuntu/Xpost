import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router, { warmRouteChunks } from './router';
import { ensureCsrf } from './lib/api';
import App from './App.vue';

createApp(App)
    .use(createPinia())
    .use(router)
    .mount('#app');

/*
 * Two round trips taken out of the way before anything waits on them.
 *
 * Every request costs a full trip to the edge and back, so what makes a page
 * feel slow is not the queries behind it but how many trips are chained one
 * after another. Both of these are chained today: the first POST on a page
 * stops to fetch the XSRF cookie, and the first visit to a section stops to
 * download its chunk.
 *
 * Neither is needed at this instant and neither blocks the first paint, so
 * both are started now and are already there when something asks.
 */
ensureCsrf();
warmRouteChunks();
