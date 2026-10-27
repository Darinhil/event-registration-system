import { defineStore } from 'pinia'
import api from '../services/api'

const TITLE_SUFFIX = 'Event Management'

// System-wide settings managed on /admin/settings. All values are public (GET /settings).
export const useSettingsStore = defineStore('settings', {
  state: () => ({
    site_name: '',
    registration_notice: '',
    maintenance_mode: false,
    loaded: false,
  }),
  actions: {
    apply(data = {}) {
      this.site_name = data.site_name || ''
      this.registration_notice = data.registration_notice || ''
      this.maintenance_mode = !!data.maintenance_mode
      this.loaded = true
      if (this.site_name) document.title = `${this.site_name} · ${TITLE_SUFFIX}`
    },
    async load({ force = false } = {}) {
      if (this.loaded && !force) return
      try {
        this.apply((await api.get('/settings')).data.data)
      } catch {
        // Settings only add notices and the tab title; the app works without them.
      }
    },
  },
})

export { TITLE_SUFFIX }
