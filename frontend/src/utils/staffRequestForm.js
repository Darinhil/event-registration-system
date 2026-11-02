import { nextTick, onMounted, reactive, ref } from 'vue'
import { getOvertimeSupervisors } from '../services/overtimeService'
import { submitRequest } from '../services/staffRequestService'

/** A nested object as multipart form fields: details[items][0][description]=… */
export const toFormData = (value, body = new FormData(), prefix = '') => {
  if (value instanceof File) body.append(prefix, value)
  else if (Array.isArray(value)) value.forEach((item, index) => toFormData(item, body, `${prefix}[${index}]`))
  else if (value && typeof value === 'object') Object.entries(value).forEach(([key, item]) => toFormData(item, body, prefix ? `${prefix}[${key}]` : key))
  else body.append(prefix, value === true ? '1' : value === false ? '0' : value ?? '')
  return body
}

export const saveBlob = (blob, name) => {
  const anchor = document.createElement('a')
  anchor.href = URL.createObjectURL(blob)
  anchor.download = name
  anchor.click()
  setTimeout(() => URL.revokeObjectURL(anchor.href), 1000)
}

/** What every request form shares: the approver list, submitting, and server errors keyed by field. */
export const useStaffRequestForm = (type) => {
  const approvers = ref([])
  const approverId = ref('')
  const attachment = ref(null)
  const saving = ref(false)
  const saved = ref(null)
  const formError = ref('')
  const errors = reactive({})
  const formEl = ref(null)

  onMounted(async () => {
    try { approvers.value = (await getOvertimeSupervisors()).data.data } catch { formError.value = 'We couldn’t load the list of approvers. Reload the page to try again.' }
  })

  const clearErrors = () => { formError.value = ''; Object.keys(errors).forEach((key) => delete errors[key]) }
  const focusFirstError = async () => { await nextTick(); formEl.value?.querySelector('[aria-invalid="true"]')?.focus() }
  const submit = async (details) => {
    if (saving.value) return null
    saving.value = true
    clearErrors()
    try {
      const payload = { type, approver_id: approverId.value, details }
      const body = attachment.value ? toFormData({ ...payload, attachment: attachment.value }) : payload
      saved.value = (await submitRequest(body)).data.data
      return saved.value
    } catch (error) {
      const fields = error.response?.data?.errors
      if (fields) Object.entries(fields).forEach(([key, messages]) => { errors[key.replace(/^details\./, '')] = [messages].flat()[0] })
      else formError.value = error.response?.data?.message || 'We couldn’t reach the server. Your answers are still here — try again.'
      focusFirstError()
      return null
    } finally {
      saving.value = false
    }
  }
  const reset = () => { saved.value = null; attachment.value = null; clearErrors() }

  return { approvers, approverId, attachment, saving, saved, formError, errors, formEl, submit, reset, focusFirstError }
}
