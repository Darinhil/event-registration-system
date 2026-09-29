import api from './api'
export const checkIn = (qr_token) => api.post('/check-ins', { qr_token })
export const previewCheckIn = (payload) => api.post('/check-ins/preview', payload)
export const lookupCheckIn = (qr_token) => api.post('/admin/check-ins/lookup', { qr_token })
export const searchCheckIns = (event_id, query) => api.post('/admin/check-ins/search', { event_id, query })