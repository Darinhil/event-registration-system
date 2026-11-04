import api from './api'
export const getMyCompliances = () => api.get('/me/compliances')
export const completeCompliance = (policyId, data) => api.post(`/me/compliances/${policyId}/complete`, data)
export const getCompliancePolicies = () => api.get('/admin/compliance-policies')
export const createCompliancePolicy = (data) => api.post('/admin/compliance-policies', data)
export const updateCompliancePolicy = (policyId, data) => api.put(`/admin/compliance-policies/${policyId}`, data)
export const deleteCompliancePolicy = (policyId) => api.delete(`/admin/compliance-policies/${policyId}`)
