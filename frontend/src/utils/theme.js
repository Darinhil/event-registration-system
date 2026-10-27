import { ref, watch } from 'vue'

// Light/dark theme for the admin area. The choice is remembered per browser;
// until someone picks one we follow the operating system setting.
const KEY = 'admin-theme'
const read = () => { try { return localStorage.getItem(KEY) } catch { return null } }
const systemPrefersDark = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches

export const theme = ref(read() === 'dark' || read() === 'light' ? read() : (systemPrefersDark() ? 'dark' : 'light'))

let active = false
const apply = () => { if (active) document.documentElement.dataset.theme = theme.value; else delete document.documentElement.dataset.theme }
watch(theme, (value) => { try { localStorage.setItem(KEY, value) } catch { /* storage unavailable */ } apply() })

export const toggleTheme = () => { theme.value = theme.value === 'dark' ? 'light' : 'dark' }
// Only admin pages are themed; public pages keep their light design.
export const setThemeActive = (on) => { active = on; apply() }
