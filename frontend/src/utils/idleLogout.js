// Signs out a browser that someone forgot to log out of. Staff accounts can open the admin
// tools, so they get the shorter limit. Activity in any tab counts (shared through localStorage),
// and the limit is checked on start-up too, so a browser reopened the next day is already signed out.
const STAFF_IDLE_MS = 30 * 60 * 1000
const ATTENDEE_IDLE_MS = 2 * 60 * 60 * 1000
const LAST_ACTIVE_KEY = 'event_last_active'
const CHECK_EVERY_MS = 30 * 1000
const WRITE_EVERY_MS = 15 * 1000

let lastWrite = 0
let signedOutForIdle = false

export const markActive = () => {
  lastWrite = Date.now()
  try { localStorage.setItem(LAST_ACTIVE_KEY, String(lastWrite)) } catch { /* storage blocked: the server-side token expiry still applies */ }
}

export const clearActivity = () => {
  try { localStorage.removeItem(LAST_ACTIVE_KEY) } catch { /* nothing to clear */ }
}

/** True once after an idle sign-out, so the sign-in page can say why. */
export const takeIdleSignOut = () => {
  const value = signedOutForIdle
  signedOutForIdle = false
  return value
}

const lastActive = () => {
  try { return Number(localStorage.getItem(LAST_ACTIVE_KEY)) || 0 } catch { return 0 }
}

/** Call before the first navigation finishes, so an expired session never opens a protected page. */
export const startIdleLogout = (auth, router) => {
  const limit = () => (auth.canAccessAdmin ? STAFF_IDLE_MS : ATTENDEE_IDLE_MS)
  // Already on a page that needs an account: go to sign-in. Elsewhere the header simply switches to "Log in".
  const leaveProtectedPage = () => {
    const current = router.currentRoute.value
    if (current.meta.auth || current.meta.staff) router.replace({ path: '/login', query: { redirect: current.fullPath, ...(takeIdleSignOut() ? { idle: '1' } : {}) } })
  }

  // Sessions from before this was added have no timestamp yet: start counting now rather than signing them out.
  if (auth.token && !lastActive()) markActive()

  const check = () => {
    if (!auth.token || Date.now() - lastActive() < limit()) return
    signedOutForIdle = true
    auth.logout()
    leaveProtectedPage()
  }

  const onActivity = () => {
    if (!auth.token) return
    if (Date.now() - lastActive() >= limit()) { check(); return } // back after the limit: sign out, don't extend
    if (Date.now() - lastWrite >= WRITE_EVERY_MS) markActive()
  }
  for (const type of ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll']) {
    window.addEventListener(type, onActivity, { capture: true, passive: true })
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check() })
  // Signing out in one tab signs out the others.
  window.addEventListener('storage', (event) => {
    if (event.key === 'event_token' && !event.newValue && auth.token) {
      auth.clearSession()
      leaveProtectedPage()
    }
  })
  setInterval(check, CHECK_EVERY_MS)
  check()
}
