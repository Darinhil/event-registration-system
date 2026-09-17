import api from './api'
export const checkIn = (qr_token) => api.post('/check-ins', { qr_token })