import { defineStore } from 'pinia'
import { checkIn, lookupCheckIn, previewCheckIn, searchCheckIns } from '../services/checkInService'
export const useCheckInStore = defineStore('checkIn', {
  state: () => ({
    result: null,
    attendee: null,
    registration: null,
    loading: false,
    checkingIn: false,
    lookupLoading: false,
    searchLoading: false,
    error: null,
    searchResult: null,
    suggestions: [],
  }),
  actions: {
    async lookup(credential) {
      this.lookupLoading = true
      this.error = null
      try {
        this.attendee = (await lookupCheckIn(credential)).data.data
        return this.attendee
      } catch (error) {
        this.attendee = null
        this.error = error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || 'Attendee lookup failed.'
        throw error
      } finally {
        this.lookupLoading = false
      }
    },
    async preview(params) {
      this.loading = true
      this.error = null
      try {
        const { data } = await previewCheckIn(params)
        this.registration = data.data
        return this.registration
      } catch (error) {
        this.registration = null
        this.error = error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || 'Preview failed.'
        throw error
      } finally {
        this.loading = false
      }
    },
    async search(eventId, query) {
      this.searchLoading = true
      this.error = null
      try {
        const { data } = await searchCheckIns({ event_id: eventId, query })
        this.searchResult = data.data?.registration || null
        this.suggestions = data.data?.suggestions || []
        return { registration: this.searchResult, suggestions: this.suggestions }
      } catch (error) {
        this.searchResult = null
        this.suggestions = []
        this.error = error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || 'Search failed.'
        throw error
      } finally {
        this.searchLoading = false
      }
    },
    async submit(credential) {
      this.loading = true
      this.checkingIn = true
      this.error = null
      try {
        this.result = (await checkIn(credential)).data
        return this.result
      } catch (error) {
        this.error = error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || 'Check-in failed.'
        throw error
      } finally {
        this.loading = false
        this.checkingIn = false
      }
    },
    reset() {
      this.result = null
      this.attendee = null
      this.registration = null
      this.error = null
      this.searchResult = null
      this.suggestions = []
    },
  },
})