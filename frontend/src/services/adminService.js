import api from './api'
export const getDashboard = () => api.get('/admin/dashboard')
export const getUsers = () => api.get('/admin/users')
export const getCheckIns = () => api.get('/admin/check-ins')