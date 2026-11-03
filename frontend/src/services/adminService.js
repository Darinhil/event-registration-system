import api from './api'
export const getDashboard = () => api.get('/admin/dashboard')
export const getUsers = (params) => api.get('/admin/users', { params })
export const getReports = () => api.get('/admin/reports')
export const getEventReportAttendees = (eventId, params) => api.get(`/admin/events/${eventId}/report-attendees`, { params })
export const getAdminEvents = (params) => api.get('/admin/events', { params })
export const regenerateEventCheckInQr = (eventId) => api.post(`/admin/events/${eventId}/check-in-qr`)
export const getEventCheckInSummary = (eventId) => api.get(`/admin/events/${eventId}/check-ins`)
