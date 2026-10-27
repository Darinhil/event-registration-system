import api from './api'
export const getMyContracts = () => api.get('/me/contracts')
export const getStaffContracts = (userId) => api.get('/admin/contracts', { params: { user_id: userId } })
export const getStaffAccounts = () => api.get('/admin/admin-accounts')
export const createContract = (formData) => api.post('/admin/contracts', formData)
export const updateContract = (id, formData) => api.post(`/admin/contracts/${id}`, formData)
export const deleteContract = (id) => api.delete(`/admin/contracts/${id}`)
export const downloadContractFile = (id) => api.get(`/contracts/${id}/file`, { responseType: 'blob' })
