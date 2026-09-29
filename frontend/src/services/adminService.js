import api from './api'
export const getDashboard = () => api.get('/admin/dashboard')
export const getReports = () => api.get('/admin/reports')
export const getEventReportAttendees = (eventId, params) => api.get(`/admin/events/${eventId}/report-attendees`, { params })
export const getUsers = (params) => api.get('/admin/users', { params })
export const getCheckIns = () => api.get('/admin/check-ins')
export const getEventCheckIns = (eventId) => api.get(`/admin/events/${eventId}/check-ins`)