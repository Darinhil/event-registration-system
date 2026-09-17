import { defineStore } from 'pinia'
import { createRegistration } from '../services/registrationService'
export const useRegistrationStore = defineStore('registration', { state: () => ({ current: null, loading: false, error: null }), actions: {
  async register(form) { this.loading = true; this.error = null; try { const { data } = await createRegistration(form); this.current = data.data; return this.current } catch (error) { this.error = error.response?.data?.message || 'Registration failed.'; throw error } finally { this.loading = false } },
} })