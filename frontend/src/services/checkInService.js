import api from './api'
export const lookupCheckIn = (credential) => api.get('/admin/check-ins/lookup', { params: { credential } })
export const previewCheckIn = (params) => api.post('/check-ins/preview', params)
export const checkIn = (credential) => api.post('/check-ins', { credential })
export const searchCheckIns = (data) => api.post('/admin/check-ins/search', data)
