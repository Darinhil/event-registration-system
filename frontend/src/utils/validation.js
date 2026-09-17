export const required = (value) => Boolean(value?.trim())
export const validEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)