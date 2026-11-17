import api from './api'

export const getFinanceVouchers = () => api.get('/admin/finance-vouchers')
export const getFinanceVoucher = (id) => api.get(`/admin/finance-vouchers/${id}`)
export const createFinanceVoucher = (payload) => api.post('/admin/finance-vouchers', payload)
export const updateFinanceVoucher = (id, payload) => api.put(`/admin/finance-vouchers/${id}`, payload)
export const transitionFinanceVoucher = (id, action) => api.patch(`/admin/finance-vouchers/${id}/workflow`, { action })
export const getFinanceBudgets = () => api.get('/admin/finance-budgets')
export const createFinanceBudget = (payload) => api.post('/admin/finance-budgets', payload)
export const updateFinanceBudget = (id, payload) => api.put(`/admin/finance-budgets/${id}`, payload)
export const transitionFinanceBudget = (id, action) => api.patch(`/admin/finance-budgets/${id}/workflow`, { action })
export const getFinanceReport = () => api.get('/admin/finance-reports')
