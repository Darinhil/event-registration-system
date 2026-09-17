import { defineStore } from 'pinia'
import { checkIn } from '../services/checkInService'
export const useCheckInStore = defineStore('checkIn', { state: () => ({ result: null, loading: false, error: null }), actions: {
  async submit(token) { this.loading = true; this.error = null; try { this.result = (await checkIn(token)).data; return this.result } catch (error) { this.error = error.response?.data?.message || 'Check-in failed.'; throw error } finally { this.loading = false } },
} })