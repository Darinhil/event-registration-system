import api from './api'
export const createRegistration = (data) => api.post('/registrations', data)
export const getRegistration = (id) => api.get(`/registrations/${id}`)