import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';

const routes = [
    // Public marketing surface. `public` means the guard lets anyone through --
    // signed in or not -- which is different from `guest` (signed-out only).
    { path: '/', name: 'landing', component: () => import('./pages/Landing.vue'), meta: { public: true } },
    { path: '/brand', name: 'brand', component: () => import('./pages/Brand.vue'), meta: { public: true } },

    { path: '/login', name: 'login', component: () => import('./pages/Login.vue'), meta: { guest: true } },

    { path: '/composer', name: 'composer', component: () => import('./pages/Composer.vue') },
    { path: '/composer/:id', name: 'composer.edit', component: () => import('./pages/Composer.vue'), props: true },
    { path: '/schedules', name: 'schedules', component: () => import('./pages/Schedules.vue') },
    { path: '/posts', name: 'posts', component: () => import('./pages/Posts.vue') },
    { path: '/posts/:id', name: 'posts.show', component: () => import('./pages/PostDetail.vue'), props: true },
    { path: '/accounts', name: 'accounts', component: () => import('./pages/Accounts.vue') },
    { path: '/visibility', name: 'visibility', component: () => import('./pages/Visibility.vue') },
    { path: '/apps', name: 'apps', component: () => import('./pages/Apps.vue') },
    { path: '/proxies', name: 'proxies', component: () => import('./pages/Proxies.vue') },
    { path: '/links', name: 'links', component: () => import('./pages/Links.vue') },
    { path: '/members', name: 'members', component: () => import('./pages/Members.vue') },
    { path: '/analytics', name: 'analytics', component: () => import('./pages/Analytics.vue') },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior(to) {
        // Let the marketing anchors work without fighting the router.
        return to.hash ? { el: to.hash, behavior: 'smooth' } : { top: 0 };
    },
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    // One identity fetch per page load, shared by every navigation after it.
    if (!auth.resolved) {
        await auth.fetchUser();
    }

    if (to.meta.public) {
        return true;
    }

    if (!to.meta.guest && !auth.user) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guest && auth.user) {
        return { name: 'composer' };
    }

    return true;
});

/**
 * Pull every route's chunk down while the browser is idle.
 *
 * Each page is a separate lazy chunk, so the first visit to a section waits on
 * a download before it can even start asking for data. Behind a tunnel that is
 * most of a second of nothing happening, and it is paid once per section per
 * deploy -- which is exactly the "switching sections is slow" that gets
 * noticed, because it never happens on the section you are already on.
 *
 * Fetching them costs nothing that matters: they are content-hashed and served
 * from the edge cache, the work happens after the first page is up, and a
 * failure here is a chunk that loads normally later.
 */
export function warmRouteChunks() {
    const warm = () => routes.forEach((route) => {
        try {
            route.component?.();
        } catch {
            // A chunk that will not prefetch still loads on navigation.
        }
    });

    if ('requestIdleCallback' in window) {
        requestIdleCallback(warm, { timeout: 4000 });
    } else {
        setTimeout(warm, 2000);
    }
}

export default router;
