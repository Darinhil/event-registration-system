// Timesheet maths and checks, mirroring TimesheetController so problems show while typing.
// The server re-checks everything on save and submit.

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
export const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

export const leaveDaysOf = (entry) => (!entry?.leave_type ? 0 : entry.leave_part === 'full' ? 1 : 0.5)
export const dayLabel = (year, month, day) => new Date(year, month - 1, day).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }).replace(',', '')
export const formatHours = (value) => `${Number(value || 0).toFixed(2).replace(/\.?0+$/, '')} h`

/** The rows of a Monday-first calendar: arrays of 7 slots, null for days outside the month. */
export function weeksOf(days) {
  const weeks = []
  let week = Array(days[0].weekday - 1).fill(null)
  for (const day of days) {
    week.push(day)
    if (week.length === 7) { weeks.push(week); week = [] }
  }
  if (week.length) weeks.push([...week, ...Array(7 - week.length).fill(null)])
  return weeks
}

/** Hours problem for one value, or '' when fine. */
export function hoursError(value, { max = 24, step = 0.25 } = {}) {
  if (value === null || value === undefined || value === '') return ''
  const number = Number(value)
  if (Number.isNaN(number)) return 'Enter a number of hours.'
  if (number < 0) return 'Hours can’t be negative.'
  if (number > max) return max < 24 ? `A half day of leave allows at most ${max} hours.` : 'A day can’t have more than 24 hours.'
  if (Math.round(number * 100) % Math.round(step * 100) !== 0) return 'Use steps of 15 minutes (0.25 h).'
  return ''
}

/**
 * Everything the page shows about a month: each day's state, totals vs expected,
 * live leave balances and the list of issues (errors block submitting, warnings don't).
 */
export function analyse({ year, month, days, entries, balances, rules }) {
  const stateOf = {}
  const issues = []
  const used = {}
  let entered = 0
  let expected = 0
  let leaveDays = 0
  // code: 'entry' problems stop saving; 'missing' and 'balance' only stop submitting; warnings never block.
  const add = (day, level, message, code) => { issues.push({ day, level, message, code }); (stateOf[day].issues ||= []).push({ level, message, code }) }

  for (const info of days) {
    const entry = entries[info.day] || {}
    const hours = Number(entry.hours || 0)
    const leave = leaveDaysOf(entry)
    const state = stateOf[info.day] = { issues: [] }
    if (!info.working) { state.kind = info.holiday ? 'holiday' : 'weekend'; continue }

    expected += rules.standard_hours
    entered += hours
    if (entry.leave_type) { leaveDays += leave; used[entry.leave_type] = (used[entry.leave_type] || 0) + leave }
    state.kind = entry.leave_type ? (entry.leave_part === 'full' ? 'leave' : 'half') : hours > 0 ? 'worked' : 'missing'

    const max = entry.leave_type && entry.leave_part !== 'full' ? rules.half_day_max : 24
    const problem = hoursError(entry.hours, { max, step: rules.step })
    if (problem) add(info.day, 'error', problem, 'entry')
    if (entry.leave_part === 'full' && hours > 0) add(info.day, 'error', 'A full day of leave can’t also have working hours.', 'entry')
    if (state.kind === 'missing') add(info.day, 'error', 'Missing — enter hours or leave.', 'missing')
  }

  // Sick leave on more than 2 consecutive working days should have a medical certificate.
  let run = []
  const flushSick = () => {
    if (run.length > 2 && !run.some((day) => entries[day]?.attachment)) run.forEach((day) => add(day, 'warning', 'Sick leave over 2 days in a row usually needs a medical certificate.', 'certificate'))
    run = []
  }
  for (const info of days.filter((item) => item.working)) {
    if (entries[info.day]?.leave_type === 'sick') run.push(info.day)
    else flushSick()
  }
  flushSick()

  const live = balances.map((balance) => {
    const usedThisMonth = used[balance.type] || 0
    const remaining = balance.entitlement + balance.carried_over - balance.used_before - balance.pending - usedThisMonth
    return { ...balance, used_this_month: usedThisMonth, remaining }
  })
  for (const balance of live.filter((item) => item.remaining < 0)) {
    const daysOfType = days.filter((info) => entries[info.day]?.leave_type === balance.type).map((info) => info.day)
    daysOfType.forEach((day) => add(day, 'error', `Not enough ${balance.label.toLowerCase()} leave left (${balance.remaining} days).`, 'balance'))
  }

  issues.sort((a, b) => a.day - b.day || (a.level === 'error' ? -1 : 1))
  const accounted = entered + leaveDays * rules.standard_hours
  return {
    stateOf,
    issues,
    errors: issues.filter((item) => item.level === 'error'),
    saveBlocked: issues.some((item) => item.code === 'entry'),
    warnings: issues.filter((item) => item.level === 'warning'),
    balances: live,
    totals: { expected, entered, accounted, difference: Math.round((accounted - expected) * 100) / 100, leaveDays, workingDays: days.filter((item) => item.working).length },
    label: (day) => dayLabel(year, month, day),
  }
}
