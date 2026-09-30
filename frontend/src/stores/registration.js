import { defineStore } from 'pinia'
import { createRegistration } from '../services/registrationService'

const isFile = (value) => typeof File !== 'undefined' && value instanceof File

export const useRegistrationStore = defineStore('registration', {
  state: () => ({ current: null, loading: false, error: null }),
  actions: {
    async register(form) {
      this.loading = true
      this.error = null
      try {
        const hasUpload = isFile(form.profile_photo) || Object.values(form.form_data || {}).some(isFile)
        const payload = hasUpload
          ? Object.entries(form).reduce((body, [key, value]) => {
            if (key === 'form_data' && value && typeof value === 'object') {
              Object.entries(value).forEach(([fieldId, answer]) => {
                if (Array.isArray(answer)) {
                  answer.forEach((item) => body.append(`form_data[${fieldId}][]`, item ?? ''))
                } else {
                  body.append(`form_data[${fieldId}]`, answer === true ? '1' : answer === false ? '0' : answer ?? '')
                }
              })
            } else if (Array.isArray(value)) {
              value.forEach((item) => body.append(`${key}[]`, item ?? ''))
            } else {
              body.append(key, value ?? '')
            }
            return body
          }, new FormData())
          : form
        const { data } = await createRegistration(payload)
        this.current = data.data
        return this.current
      } catch (error) {
        const validationMessage = Object.values(error.response?.data?.errors || {}).flat()[0]
        this.error = validationMessage || error.response?.data?.message || (error.response ? `Registration failed (API ${error.response.status}).` : 'Cannot connect to the registration service.')
        throw error
      } finally {
        this.loading = false
      }
    },
  },
})
