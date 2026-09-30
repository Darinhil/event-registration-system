import api from './api'
export const getDashboard = () => api.get('/admin/dashboard')
export const getUsers = (params) => api.get('/admin/users', { params })
export const getCheckIns = (params) => api.get('/admin/check-ins', { params })