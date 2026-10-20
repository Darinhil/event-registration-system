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
api.interceptors.request.use((config) => { const token = localStorage.getItem('event_token'); if (token) config.headers.Authorization = `Bearer ${token}`; return config })
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const isLoginRequest = /\/auth\/(?:login|admin\/login|register)(?:\?|$)/.test(error.config?.url || '')
    if (error.response?.status === 401 && localStorage.getItem('event_token') && !isLoginRequest) {
      localStorage.removeItem('event_token')
      localStorage.removeItem('event_user')
      const redirect = `${window.location.pathname}${window.location.search}${window.location.hash}`
      window.location.assign(`/login?session=expired&redirect=${encodeURIComponent(redirect)}`)
    }
    return Promise.reject(error)
  }
)
export default api
