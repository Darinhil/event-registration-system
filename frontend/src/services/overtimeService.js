import api from './api'
export const getMyOvertime = () => api.get('/me/overtime')
export const getOvertimeSupervisors = () => api.get('/me/overtime/supervisors')
export const submitOvertime = (data) => api.post('/me/overtime', data)
export const cancelOvertime = (id) => api.post(`/overtime/${id}/cancel`)
export const getOvertimeApprovals = () => api.get('/me/overtime/approvals')
export const decideOvertime = (id, decision, note) => api.post(`/overtime/${id}/decision`, { decision, note })
