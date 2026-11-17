import api from './api'

export const getProcurementDocuments = () => api.get('/me/procurement-documents')
export const createProcurementDocument = (body) => api.post('/me/procurement-documents', body)
export const updateProcurementDocument = (id, body) => api.put(`/me/procurement-documents/${id}`, body)
export const transitionProcurementDocument = (id, status) => api.patch(`/me/procurement-documents/${id}/status`, { status })
