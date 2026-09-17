import { defineStore } from 'pinia'
import api from '../services/api'

export const useAuthStore = defineStore('auth', { state: () => ({ user: null, token: localStorage.getItem('event_token') }), actions: {
  async login(credentials) { const { data } = await api.post('/auth/login', credentials); this.user = data.data; this.token = data.token; localStorage.setItem('event_token', data.token) },
  logout() { this.user = null; this.token = null; localStorage.removeItem('event_token') },
} })