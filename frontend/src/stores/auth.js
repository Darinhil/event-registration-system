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

let profileStateVersion = 0

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: JSON.parse(localStorage.getItem('event_user') || 'null'),
    token: localStorage.getItem('event_token'),
    profilePhotoPreview: null,
    loading: false,
    error: null,
  }),
  getters: {
    isAuthenticated: (state) => Boolean(state.token),
    isAdmin: (state) => ['admin', 'master_admin'].includes(state.user?.role),
    isEventAdmin: (state) => state.user?.role === 'event_admin',
    hasPermission: (state) => (permission) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).includes(permission),
    canViewEvents: (state) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).includes('events.view'),
    canManageEvents: (state) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).includes('events.manage'),
    canViewReports: (state) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).includes('reports.view'),
    canManageCheckins: (state) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).includes('checkins.manage'),
    canAccessAdmin: (state) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).some((permission) => ['users.create', 'users.manage', 'roles.manage', 'workflows.manage', 'events.view', 'events.manage', 'forms.create', 'forms.review', 'forms.verify', 'forms.approve', 'reports.view', 'checkins.manage'].includes(permission)),
    canManageUsers: (state) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).some((permission) => ['users.create', 'users.manage'].includes(permission)),
    canManageAccounts: (state) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).includes('users.manage'),
    canAccessSettings: (state) => ['admin', 'master_admin'].includes(state.user?.role) || (state.user?.permissions || []).some((permission) => ['users.manage', 'roles.manage', 'workflows.manage'].includes(permission)),
    roleLabel: (state) => ({ admin: 'Master Admin', master_admin: 'Master Admin', maker: 'Maker', checker: 'Checker', reviewer: 'Reviewer', verifier: 'Verifier', approver: 'Approver', event_admin: 'Event Admin', manager: 'Manager', officer_3: 'Officer Level 3', officer_2: 'Officer Level 2', officer_1: 'Officer Level 1', officer_0: 'Officer Level 0' }[state.user?.role] || 'Attendee'),
  },
  actions: {
    setProfilePhotoPreview(blob) {
      if (this.profilePhotoPreview?.startsWith('blob:')) URL.revokeObjectURL(this.profilePhotoPreview)
      this.profilePhotoPreview = URL.createObjectURL(blob)
      return this.profilePhotoPreview
    },
    clearProfilePhotoPreview() {
      if (this.profilePhotoPreview?.startsWith('blob:')) URL.revokeObjectURL(this.profilePhotoPreview)
      this.profilePhotoPreview = null
    },
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
      const requestVersion = profileStateVersion
      const { data } = await api.get('/me')
      if (requestVersion !== profileStateVersion) return
      this.user = normalizeUserPhoto(data.data)
      if (!this.user?.profile_photo) this.clearProfilePhotoPreview()
      localStorage.setItem('event_user', JSON.stringify(this.user))
    },
    async updateProfile(payload) {
      // Invalidate any older /me request so it cannot restore stale profile data.
      profileStateVersion += 1
      this.loading = true
      this.error = null
      try {
        // Let Axios/browser add the multipart boundary automatically.
        const { data } = await api.post('/me/profile', payload)
        this.user = normalizeUserPhoto(data.data)
        if (!this.user?.profile_photo) this.clearProfilePhotoPreview()
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
      try { if (this.token) await api.post('/auth/logout') } finally { this.clearProfilePhotoPreview(); this.user = null; this.token = null; localStorage.removeItem('event_token'); localStorage.removeItem('event_user') }
    },
  },
})
