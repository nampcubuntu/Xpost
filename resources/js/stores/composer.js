import { defineStore } from 'pinia';
import api from '../lib/api';

/**
 * The Website field deliberately shows the protocol as a fixed prefix, so the
 * editable part is a hostname/path. Keep the model and every API request as a
 * complete URL; otherwise Laravel rightly rejects `example.com` as malformed.
 */
export function normalizeWebsiteUrl(value) {
    const url = String(value ?? '').trim();

    if (!url) {
        return '';
    }

    return /^https?:\/\//i.test(url) ? url : `https://${url}`;
}

/** Mirrors the server-side defaults in StorePostRequest::prepareForValidation. */
function blankPost() {
    return {
        name: '',
        body: '',
        reply_settings: 'everyone',
        // No destination by default: the reference composer starts unselected,
        // and a post without a link costs $0.015 instead of $0.20 on X pay-per-use.
        destination_type: 'none',
        website_url: '',
        headline: '',
        app_store_url: '',
        is_ai_generated: false,
        /*
         * Off by default, unlike X Ads Manager, which is an ads tool and starts
         * it on. A promoted-only post is created and live but never appears on
         * the author's timeline, so leaving it on by default would let a run of
         * 5,000 posts finish green with nobody able to see any of them. A post
         * that appears when it should not is deletable; one that silently never
         * appears goes unnoticed.
         */
        promoted_only: false,
        variant_strategy: 'same',
        variants: [],
        media_ids: [],
        schedule_mode: 'immediate',
        // Sent explicitly: a <input type="datetime-local"> gives a bare wall
        // clock, so without this the server has to guess whose clock it was.
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        scheduled_at: null,
        window_ends_at: null,
        jitter_seconds: 0,
        per_minute_cap: null,
        audience: { mode: 'all', groups: [], accounts: [], exclude_accounts: [], filters: {}, shuffle: true },
    };
}

export const useComposerStore = defineStore('composer', {
    state: () => ({
        post: blankPost(),
        media: [],
        options: null,
        measure: { weighted_length: 0, remaining: 280, max: 280, permutations: 1 },
        // Real posts this template produces, not a count of them.
        samples: [],
        estimate: null,
        errors: {},
        saving: false,
        savedPostId: null,
    }),

    getters: {
        overLimit: (state) => state.measure.remaining < 0,
        hasContent: (state) => state.post.body.trim().length > 0 || state.post.media_ids.length > 0,
        usesSpintax: (state) => state.measure.permutations > 1,
    },

    actions: {
        reset() {
            this.post = blankPost();
            this.media = [];
            this.errors = {};
            this.estimate = null;
            this.savedPostId = null;
        },

        async loadOptions() {
            if (this.options) {
                return;
            }

            const { data } = await api.get('composer/options');
            this.options = data;
        },

        async load(id) {
            const { data } = await api.get(`posts/${id}`);
            const post = data.data;

            this.savedPostId = post.id;
            this.post = {
                ...blankPost(),
                ...post,
                media_ids: (post.media ?? []).map((m) => m.id),
                variants: post.variants ?? [],
                audience: post.audience ?? blankPost().audience,
            };
            this.media = post.media ?? [];
        },

        /** Server-side character count: X's weighting rules, not the browser's. */
        async refreshMeasure() {
            const { data } = await api.post('composer/measure', {
                body: this.post.body,
                website_url: this.post.destination_type === 'website'
                    ? normalizeWebsiteUrl(this.post.website_url)
                    : null,
            });

            this.measure = data;
        },

        /**
         * A few of the posts this template will actually produce.
         *
         * The permutation count alone says nothing useful: "1,024 variants" is
         * a number, and nobody turns a feature on for a number. Five real posts
         * side by side is what shows whether the spin reads naturally or
         * produces nonsense -- and reading them is the only way to catch a
         * bracket in the wrong place before a thousand accounts publish it.
         */
        async refreshSamples() {
            if (!this.usesSpintax) {
                this.samples = [];

                return;
            }

            try {
                const { data } = await api.post('composer/samples', {
                    body: this.post.body,
                    count: 5,
                });

                this.samples = data.samples;
            } catch {
                // Cosmetic: a failed preview must not block composing.
                this.samples = [];
            }
        },

        async refreshEstimate() {
            const { data } = await api.post('composer/estimate', {
                audience: this.post.audience,
                schedule_mode: this.post.schedule_mode,
                timezone: this.post.timezone,
                scheduled_at: this.post.scheduled_at,
                window_ends_at: this.post.window_ends_at,
                jitter_seconds: this.post.jitter_seconds,
                per_minute_cap: this.post.per_minute_cap,
                // For the duplicate check: what this content has already gone
                // out to, rather than how many accounts it is heading for.
                media_ids: this.media.map((item) => item.id),
                website_url: this.post.destination_type === 'website'
                    ? normalizeWebsiteUrl(this.post.website_url)
                    : null,
                post_id: this.post.id ?? null,
            });

            this.estimate = data;
        },

        async attachMedia(file) {
            const form = new FormData();
            form.append('file', file);

            const { data } = await api.post('media', form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            this.media.push(data.data ?? data);
            this.post.media_ids.push((data.data ?? data).id);
        },

        /** Replace the whole attachment set -- what the media picker returns. */
        setMedia(list) {
            this.media = list;
            this.post.media_ids = list.map((m) => m.id);
        },

        detachMedia(id) {
            this.media = this.media.filter((m) => m.id !== id);
            this.post.media_ids = this.post.media_ids.filter((m) => m !== id);
        },

        async save(status = 'draft') {
            this.saving = true;
            this.errors = {};

            try {
                const payload = {
                    ...this.post,
                    status,
                    website_url: this.post.destination_type === 'website'
                        ? normalizeWebsiteUrl(this.post.website_url)
                        : this.post.website_url,
                };

                const { data } = this.savedPostId
                    ? await api.put(`posts/${this.savedPostId}`, payload)
                    : await api.post('posts', payload);

                this.savedPostId = (data.data ?? data).id;

                return data;
            } finally {
                this.saving = false;
            }
        },

        async publish() {
            const saved = await this.save('draft');
            const id = (saved.data ?? saved).id;

            const { data } = await api.post(`posts/${id}/publish`);

            return data;
        },
    },
});
