import api from './api'
export const getMyCompliances = () => api.get('/me/compliances')
export const completeCompliance = (policyId, data) => api.post(`/me/compliances/${policyId}/complete`, data)
