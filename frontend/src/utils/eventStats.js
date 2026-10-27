// Pure helpers for the admin event page: progress maths, local date/time
// formatting and the registration status. Kept free of Vue so they are easy
// to reason about and test.

const pad = (value) => String(value).padStart(2, '0')

/** Percentage of `part` in `total`, or null when there is nothing to compare against. */
export const percentOf = (part, total) => {
  const whole = Number(total)
  if (!Number.isFinite(whole) || whole <= 0) return null
  return Math.round((Math.max(0, Number(part) || 0) / whole) * 100)
}

/** Registration progress against capacity. The bar is capped at 100%; `over` flags overbooking. */
export const capacityProgress = (registered, capacity) => {
  const count = Math.max(0, Number(registered) || 0)
  const limit = Number(capacity) > 0 ? Number(capacity) : null
  const percent = limit ? percentOf(count, limit) : null
  return {
    registered: count,
    capacity: limit,
    percent,
    bar: percent === null ? 0 : Math.min(100, percent),
    remaining: limit ? Math.max(0, limit - count) : null,
    full: limit !== null && count >= limit,
    over: limit !== null && count > limit,
  }
}

/** Check-in progress measured against actual registrations, not capacity. */
export const checkInProgress = (checkedIn, registered) => {
  const count = Math.max(0, Number(checkedIn) || 0)
  const total = Math.max(0, Number(registered) || 0)
  const percent = percentOf(count, total)
  return { checkedIn: count, registered: total, percent, bar: percent === null ? 0 : Math.min(100, percent), remaining: Math.max(0, total - count) }
}

/** Splits a UTC ISO timestamp into the viewer's local date (YYYY-MM-DD) and time (HH:MM). */
export const toLocalParts = (iso) => {
  const date = iso ? new Date(iso) : null
  if (!date || Number.isNaN(date.getTime())) return { date: '', time: '', value: null }
  return { date: `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`, time: `${pad(date.getHours())}:${pad(date.getMinutes())}`, value: date }
}

const dateFormat = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }
const timeFormat = { hour: 'numeric', minute: '2-digit' }

/** Human date and time ranges, including multi-day events, in local time. */
export const scheduleLabels = (startsAt, endsAt, locale) => {
  const start = toLocalParts(startsAt)
  const end = toLocalParts(endsAt)
  if (!start.value) return { date: 'Date to be confirmed', time: 'Time to be confirmed', multiDay: false }
  const startDate = start.value.toLocaleDateString(locale, dateFormat)
  const startTime = start.value.toLocaleTimeString(locale, timeFormat)
  if (!end.value) return { date: startDate, time: startTime, multiDay: false }
  const endTime = end.value.toLocaleTimeString(locale, timeFormat)
  if (start.date === end.date) return { date: startDate, time: `${startTime} – ${endTime}`, multiDay: false }
  return { date: `${startDate} – ${end.value.toLocaleDateString(locale, dateFormat)}`, time: `${startTime} – ${endTime}`, multiDay: true }
}

export const publicationStatus = (status) => ({
  draft: { label: 'Draft', tone: 'draft', hint: 'Only your team can see this event.' },
  published: { label: 'Published', tone: 'published', hint: 'Visible to attendees.' },
  open: { label: 'Published', tone: 'published', hint: 'Visible to attendees.' },
  closed: { label: 'Closed', tone: 'closed', hint: 'The event is closed.' },
  cancelled: { label: 'Cancelled', tone: 'cancelled', hint: 'The event was cancelled.' },
}[status] || { label: status ? String(status) : 'Unknown', tone: 'draft', hint: '' })

const formatDay = (value, locale) => new Date(`${value}T00:00:00`).toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' })

/**
 * Whether attendees can register right now. Mirrors the server rules in
 * RegistrationService: published status, the "allow registration" switch,
 * the opening date, the deadline (end of that day) and capacity.
 */
export const registrationState = ({ status, settings = {}, registered = 0, capacity, endsAt, now = new Date(), locale } = {}) => {
  const today = toLocalParts(now.toISOString()).date
  const start = settings.registration_start ? String(settings.registration_start).slice(0, 10) : ''
  const deadline = settings.registration_end ? String(settings.registration_end).slice(0, 10) : ''
  const limit = Number(settings.max_participants ?? capacity) || 0
  if (status === 'draft') return { label: 'Not open', tone: 'muted', detail: 'Opens once the event is published.' }
  if (status === 'cancelled') return { label: 'Cancelled', tone: 'danger', detail: 'The event was cancelled.' }
  if (status === 'closed') return { label: 'Closed', tone: 'muted', detail: 'The event has been closed.' }
  if (settings.allow_registration === false) return { label: 'Paused', tone: 'warn', detail: 'Registration is switched off in the event settings.' }
  if (endsAt && new Date(endsAt) < now) return { label: 'Event ended', tone: 'muted', detail: 'This event has finished.' }
  if (start && start > today) return { label: 'Not open yet', tone: 'info', detail: `Opens ${formatDay(start, locale)}.` }
  if (deadline && deadline < today) return { label: 'Closed', tone: 'muted', detail: `The deadline was ${formatDay(deadline, locale)}.` }
  if (limit && registered >= limit) return { label: 'Full', tone: 'warn', detail: 'Capacity has been reached.' }
  return { label: 'Open', tone: 'ok', detail: deadline ? `Closes ${formatDay(deadline, locale)}.` : 'No deadline set.' }
}

/** Builds a CSV string; every cell is quoted and formula-like values are neutralised for spreadsheets. */
export const toCsv = (header, rows) => {
  const cell = (value) => {
    let text = value === null || value === undefined ? '' : String(value)
    if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`
    return `"${text.replaceAll('"', '""')}"`
  }
  return [header, ...rows].map((row) => row.map(cell).join(',')).join('\r\n')
}

// Where an event is in its lifecycle right now: draft | unscheduled | upcoming | ongoing | past.
export const eventPhase = (event, now = Date.now()) => {
  if (event?.status === 'draft') return 'draft'
  const starts = event?.starts_at ? new Date(event.starts_at) : null
  if (!starts || Number.isNaN(starts.getTime())) return 'unscheduled'
  const ends = event.ends_at ? new Date(event.ends_at) : starts
  if (now < starts.getTime()) return 'upcoming'
  return now <= ends.getTime() ? 'ongoing' : 'past'
}

export const phaseLabels = { draft: 'Draft', unscheduled: 'Date pending', upcoming: 'Upcoming', ongoing: 'Happening now', past: 'Ended' }
