import { defineStore } from 'pinia';
import api from '../lib/api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        teams: [],
        // Distinguishes "not logged in" from "we have not asked yet", so the
        // router guard does not bounce a signed-in user to /login on first load.
        resolved: false,
    }),

    getters: {
        currentTeam: (state) => state.teams.find((team) => team.id === state.user?.current_team_id) ?? null,
        canEdit: (state) => ['owner', 'admin', 'editor'].includes(state.user?.role),
        canManage: (state) => ['owner', 'admin'].includes(state.user?.role),
    },

    actions: {
        async fetchUser() {
            try {
                // skipAuthRedirect: a 401 here just means "nobody is signed in",
                // which is a normal answer on the public pages.
                const { data } = await api.get('me', { skipAuthRedirect: true });
                this.user = data.user;
                this.teams = data.teams;
            } catch {
                this.user = null;
                this.teams = [];
            } finally {
                this.resolved = true;
            }
        },

        async login(payload) {
            const { data } = await api.post('login', payload);
            this.user = data.user;
            this.teams = data.teams;
            this.resolved = true;
        },

        async register(payload) {
            const { data } = await api.post('register', payload);
            this.user = data.user;
            this.teams = data.teams;
            this.resolved = true;
        },

        async logout() {
            await api.post('logout');
            this.$reset();
            this.resolved = true;
        },

        async switchTeam(teamId) {
            const { data } = await api.post('teams/switch', { team_id: teamId });
            this.user = data.user;
            this.teams = data.teams;
            // Every cached list belongs to the old tenant; a reload is cheaper
            // and safer than invalidating each store by hand.
            window.location.reload();
        },
    },
});
