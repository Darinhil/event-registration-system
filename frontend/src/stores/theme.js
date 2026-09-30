import { defineStore } from 'pinia'

const STORAGE_KEY = 'event_theme'

const applyTheme = (dark) => {
  document.documentElement.classList.toggle('theme-dark', dark)
}

export const useThemeStore = defineStore('theme', {
  state: () => {
    const stored = localStorage.getItem(STORAGE_KEY)
    const dark = stored ? stored === 'dark' : window.matchMedia?.('(prefers-color-scheme: dark)').matches || false
    applyTheme(dark)
    return { dark }
  },
  actions: {
    toggle() {
      this.dark = !this.dark
      localStorage.setItem(STORAGE_KEY, this.dark ? 'dark' : 'light')
      applyTheme(this.dark)
    },
  },
})
