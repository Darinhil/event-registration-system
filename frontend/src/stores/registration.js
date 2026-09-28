import { defineStore } from 'pinia'
import { createRegistration } from '../services/registrationService'

const hasFile = (form) => Object.values(form).some((value) => value instanceof File)

/**
 * Serialise the registration payload. File answers require multipart encoding,
 * so we switch to FormData whenever one is present. Array answers are appended
 * with the `key[]` convention so Laravel receives them as arrays.
 */
const toPayload = (form) => {
  if (!hasFile(form)) return form
  return Object.entries(form).reduce((body, [key, value]) => {
    if (value === undefined) return body
    if (Array.isArray(value)) {
      if (value.length === 0) body.append(`${key}[]`, '')
      else value.forEach((item) => body.append(`${key}[]`, item))
    } else {
      body.append(key, value instanceof File ? value : (value ?? ''))
    }
    return body
  }, new FormData())
}

export const useRegistrationStore = defineStore('registration', { state: () => ({ current: null, loading: false, error: null }), actions: {
  async register(form) { this.loading = true; this.error = null; try { const { data } = await createRegistration(toPayload(form)); this.current = data.data; return this.current } catch (error) { this.error = error.response?.data?.message || 'Registration failed.'; throw error } finally { this.loading = false } },
} })