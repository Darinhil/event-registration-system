import api from './api'
const base = (year, month) => `/me/timesheets/${year}/${month}`
export const getTimesheet = (year, month) => api.get(base(year, month))
export const saveTimesheet = (year, month, data) => api.put(base(year, month), data)
export const submitTimesheet = (year, month, data) => api.post(`${base(year, month)}/submit`, data)
export const recallTimesheet = (year, month) => api.post(`${base(year, month)}/recall`)
export const uploadTimesheetAttachment = (year, month, file) => { const body = new FormData(); body.append('file', file); return api.post(`${base(year, month)}/attachments`, body) }
export const downloadTimesheetAttachment = (id, day) => api.get(`/timesheets/${id}/attachments/${day}`, { responseType: 'blob' })
export const getTimesheetApprovals = () => api.get('/me/timesheet-approvals')
export const getTimesheetForReview = (id) => api.get(`/timesheets/${id}`)
export const decideTimesheet = (id, data) => api.post(`/timesheets/${id}/decision`, data)
export const processTimesheet = (id) => api.post(`/timesheets/${id}/process`)
