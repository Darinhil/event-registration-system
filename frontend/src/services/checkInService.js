import api from './api'
export const lookupCheckIn = (credential) => api.get('/admin/check-ins/lookup', { params: { credential } })
export const previewCheckIn = (params) => api.post('/check-ins/preview', params)
export const checkIn = (credential, entranceToken, photoConsent) => api.post('/check-ins', {
  credential,
  ...(entranceToken ? { entrance_token: entranceToken } : {}),
  ...(photoConsent === null || photoConsent === undefined ? {} : { photo_consent: photoConsent }),
})
export const searchCheckIns = (data) => api.post('/admin/check-ins/search', data)