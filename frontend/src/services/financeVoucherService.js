import api from './api'

export const getFinanceVouchers = () => api.get('/admin/finance-vouchers')
export const getFinanceVoucher = (id) => api.get(`/admin/finance-vouchers/${id}`)
export const createFinanceVoucher = (payload) => api.post('/admin/finance-vouchers', payload)
export const updateFinanceVoucher = (id, payload) => api.put(`/admin/finance-vouchers/${id}`, payload)
