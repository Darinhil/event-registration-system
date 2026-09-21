import { defineStore } from 'pinia'
import api from '../services/api'

const normalizeUserPhoto = (user) => {
  if (!user?.profile_photo) return user
  try {
    const photoPath = new URL(user.profile_photo, window.location.origin).pathname
    const apiOrigin = api.defaults.baseURL.replace(/\/api\/?$/, '')
    return { ...user, profile_photo: apiOrigin + photoPath }
  } catch {
    return user
  }
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: JSON.parse(localStorage.getItem('event_user') || 'null'),
    token: localStorage.getItem('event_token'),
    loading: false,
    error: null,
  }),
  getters: { isAuthenticated: (state) => Boolean(state.token) },
  actions: {
    saveSession(data) {
      this.user = normalizeUserPhoto(data.data)
      this.token = data.token
      localStorage.setItem('event_token', data.token)
      localStorage.setItem('event_user', JSON.stringify(this.user))
    },
    async login(credentials, endpoint = '/auth/login') {
      this.loading = true
      this.error = null
      try { this.saveSession((await api.post(endpoint, credentials)).data) } catch (error) { this.error = error.response?.data?.message || 'Unable to sign in.'; throw error } finally { this.loading = false }
    },
    async registerAccount(details) {
      this.loading = true
      this.error = null
      try { this.saveSession((await api.post('/auth/register', details)).data) } catch (error) { this.error = error.response?.data?.message || 'Unable to create account.'; throw error } finally { this.loading = false }
    },
    async fetchMe() {
      if (!this.token) return
      const { data } = await api.get('/me')
      this.user = normalizeUserPhoto(data.data)
      localStorage.setItem('event_user', JSON.stringify(this.user))
    },
    async updateProfile(payload) {
      this.loading = true
      this.error = null
      try {
        // Let Axios/browser add the multipart boundary automatically.
        const { data } = await api.post('/me/profile', payload)
        this.user = normalizeUserPhoto(data.data)
        localStorage.setItem('event_user', JSON.stringify(this.user))
        return this.user
      } catch (error) {
        this.error = error.response?.data?.message || 'Unable to save profile changes.'
        throw error
      } finally {
        this.loading = false
      }
    },
    async updatePassword(payload) {
      this.loading = true
      this.error = null
      try {
        return (await api.patch('/me/password', payload)).data
      } catch (error) {
        this.error = error.response?.data?.message || 'Unable to change password.'
        throw error
      } finally {
        this.loading = false
      }
    },
    async logout() {
      try { if (this.token) await api.post('/auth/logout') } finally { this.user = null; this.token = null; localStorage.removeItem('event_token'); localStorage.removeItem('event_user') }
    },
  },
})
