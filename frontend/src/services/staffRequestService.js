import api from './api'
export const getMyRequests = () => api.get('/me/requests')
export const getRequestApprovals = () => api.get('/me/requests/approvals')
export const submitRequest = (body) => api.post('/me/requests', body)
export const cancelRequest = (id) => api.post(`/requests/${id}/cancel`)
export const decideRequest = (id, decision, note) => api.post(`/requests/${id}/decision`, { decision, note })
export const downloadRequestAttachment = (id) => api.get(`/requests/${id}/attachment`, { responseType: 'blob' })
export const getPersonnelProfile = () => api.get('/me/personnel-profile')
export const savePersonnelProfile = (profile, submit = false) => api.put('/me/personnel-profile', { profile, submit })
export const uploadPersonnelDocument = (slot, file, metadata = {}) => {
  const body = new FormData()
  body.append('file', file)
  for (const [key, value] of Object.entries(metadata)) if (value) body.append(key, value)
  return api.post(`/me/personnel-profile/documents/${slot}`, body)
}
export const deletePersonnelDocument = (slot) => api.delete(`/me/personnel-profile/documents/${slot}`)
export const downloadPersonnelDocument = (userId, slot) => api.get(`/personnel-profiles/${userId}/documents/${slot}`, { responseType: 'blob' })
export const getStaffPersonnelProfile = (userId) => api.get(`/admin/personnel-profiles/${userId}`)
