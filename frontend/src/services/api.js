import axios from 'axios'

const configuredApiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'
const apiUrl = typeof window !== 'undefined' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1'
  ? configuredApiUrl.replace('localhost', window.location.hostname).replace('127.0.0.1', window.location.hostname)
  : configuredApiUrl
const api = axios.create({
  baseURL: apiUrl,
  timeout: 15000,
  headers: { Accept: 'application/json' },
})
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('event_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  // Files can take far longer than the default on a phone connection.
  if (config.data instanceof FormData) config.timeout = 120000
  return config
})
export default api
