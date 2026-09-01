import { ref } from 'vue';
import api from './api';

/**
 * The zone list, loaded once and shared by every picker on the page.
 *
 * The accounts table renders one picker per row and per_page goes to 200, so
 * state declared inside the component is two hundred copies of the same 419
 * zones -- each one walked by Vue's reactivity on every change. Here it is one
 * array with two hundred readers.
 *
 * Module scope and not a Pinia store because nothing writes it: it is loaded
 * once from an endpoint whose answer changes when the tzdata package does.
 */
export const zones = ref([]);
export const inUse = ref([]);
export const loaded = ref(false);

/** In flight, so twenty pickers mounting together make one request. */
let request;

export async function loadTimezones() {
    request ??= api.get('timezones');

    try {
        const { data } = await request;

        zones.value = data.data;
        inUse.value = data.in_use;
    } catch {
        // A picker with no list still shows the value it was given, which is
        // the one thing it must not lose.
        request = undefined;
    } finally {
        loaded.value = true;
    }
}
