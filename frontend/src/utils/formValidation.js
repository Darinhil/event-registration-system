/**
 * Shared validation + payload helpers for attendee-facing dynamic forms.
 * Single source of truth used by the public registration form and the
 * "edit registration" page, mirroring the backend's dynamic rules.
 */

/** True when a field answer has no content (also counts unchecked boxes). */
export const isAnswerEmpty = (value) =>
  value === null ||
  value === undefined ||
  value === '' ||
  (Array.isArray(value) && value.length === 0) ||
  value === false

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_RE = /^[+]?[0-9][0-9\s().-]{7,24}$/
const URL_RE = /^https?:\/\/\S+\.\S+/

/** Validate one field; returns an error message or '' when valid. */
export const validateField = (field, value) => {
  const s = field.settings || {}
  const empty = isAnswerEmpty(value)
  if (field.required && empty) return `${field.label} is required.`
  if (empty) return ''

  switch (field.type) {
    case 'email':
      return EMAIL_RE.test(String(value)) ? '' : `${field.label} must be a valid email address.`
    case 'phone':
      return PHONE_RE.test(String(value).trim()) ? '' : `${field.label} must be a valid phone number.`
    case 'url':
      return URL_RE.test(String(value).trim()) ? '' : `${field.label} must be a valid URL (https://…).`
    case 'number': {
      const n = Number(value)
      if (Number.isNaN(n)) return `${field.label} must be a number.`
      if (s.min_value !== null && s.min_value !== undefined && s.min_value !== '' && n < Number(s.min_value)) {
        return `${field.label} must be at least ${s.min_value}.`
      }
      if (s.max_value !== null && s.max_value !== undefined && s.max_value !== '' && n > Number(s.max_value)) {
        return `${field.label} must be at most ${s.max_value}.`
      }
      return ''
    }
    case 'text':
    case 'textarea': {
      const len = String(value).length
      if (s.min_length && len < Number(s.min_length)) return `${field.label} must be at least ${s.min_length} characters.`
      if (s.max_length && len > Number(s.max_length)) return `${field.label} must be at most ${s.max_length} characters.`
      return ''
    }
    case 'file': {
      if (!(value instanceof File)) return ''
      const ext = String(value.name).split('.').pop().toLowerCase()
      const allowed = Array.isArray(s.allowed_types) && s.allowed_types.length ? s.allowed_types : ['pdf', 'jpg', 'png']
      if (!allowed.includes(ext)) return `${field.label} must be one of: ${allowed.join(', ').toUpperCase()}.`
      const maxMb = Number(s.max_size_mb || 5)
      if (value.size > maxMb * 1024 * 1024) return `${field.label} must be smaller than ${maxMb} MB.`
      return ''
    }
    default:
      return ''
  }
}

/** Validate the fields of one step; returns { fieldId: message } for invalid fields. */
export const validateFields = (fields, values) => {
  const errors = {}
  for (const field of fields) {
    if (field.type === 'heading') continue
    const message = validateField(field, values[field.id])
    if (message) errors[field.id] = message
  }
  return errors
}

/** Normalise a stored form_data value for prefilling (edit flow). */
export const normalizeStoredValue = (field, stored) => {
  const s = field.settings || {}
  if (field.type === 'checkbox' && !s.multiple) {
    return stored === true || stored === 'true' || (Array.isArray(stored) && stored[0] === true)
  }
  if (Array.isArray(stored)) return [...stored]
  if (field.type === 'file') return stored || null // storage path; replaced when a new File is picked
  return stored ?? ''
}

/** Build the form_data payload from rendered values, matching backend expectations. */
export const buildSubmission = (fields, values) => {
  const out = {}
  for (const field of fields) {
    if (field.type === 'heading') continue
    const value = values[field.id]
    if (field.type === 'checkbox' && !(field.settings || {}).multiple) {
      out[field.id] = value === true || value === 'true' || (Array.isArray(value) && value[0] === true)
      continue
    }
    if (field.type === 'file') {
      // Only send newly picked files; keep '' so the backend preserves stored paths.
      out[field.id] = value instanceof File ? value : ''
      continue
    }
    out[field.id] = value ?? ''
  }
  return out
}

/** Human answer for review screens (files show their name). */
export const displayValue = (field, value) => {
  if (isAnswerEmpty(value)) return '—'
  if (field.type === 'file') return value instanceof File ? value.name : String(value).split(/[\\/]/).pop()
  if (Array.isArray(value)) return value.join(', ')
  if (value === true) return 'Yes'
  if (value === false) return 'No'
  return String(value)
}
