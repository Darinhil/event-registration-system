import api from './api'
export const lookupCheckIn = (credential) => api.get('/check-ins/lookup', { params: { credential } })
export const checkIn = (credential) => api.post('/check-ins', { credential })