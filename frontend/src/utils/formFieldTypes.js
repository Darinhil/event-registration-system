/**
 * Field type registry for the visual Form Builder.
 * Single source of truth shared by the builder, the preview renderer,
 * and the user-facing registration form.
 */

export const FIELD_CATEGORIES = [
  {
    key: 'basic',
    label: 'Basic Fields',
    types: [
      { type: 'text', label: 'Text Input', icon: 'text', description: 'Single line of text' },
      { type: 'number', label: 'Number Input', icon: 'hash', description: 'Numeric input' },
      { type: 'email', label: 'Email', icon: 'mail', description: 'Validated email address' },
      { type: 'phone', label: 'Phone', icon: 'phone', description: 'Telephone number' },
      { type: 'textarea', label: 'Multi-line Input', icon: 'paragraph', description: 'Multi-line message' },
      { type: 'autocomplete', label: 'Autocomplete', icon: 'autocomplete', description: 'Text with suggestions' },
      { type: 'date', label: 'Date', icon: 'calendar', description: 'Date picker' },
      { type: 'time', label: 'Time', icon: 'clock', description: 'Time picker' },
    ],
  },
  {
    key: 'choice',
    label: 'Choice Fields',
    types: [
      { type: 'select', label: 'Dropdown', icon: 'chevron-down', description: 'Pick one from a list' },
      { type: 'radio', label: 'Radio Buttons', icon: 'radio', description: 'Pick one option' },
      { type: 'checkbox', label: 'Checkboxes', icon: 'check-square', description: 'Pick several options' },
      { type: 'yesno', label: 'Yes / No', icon: 'toggle', description: 'Simple boolean switch' },
    ],
  },
  {
    key: 'advanced',
    label: 'Advanced Fields',
    types: [
      { type: 'file', label: 'File Upload', icon: 'upload', description: 'Attach a document or image' },
      { type: 'url', label: 'URL', icon: 'link', description: 'Website link' },
      { type: 'address', label: 'Address', icon: 'map-pin', description: 'Street address' },
      { type: 'country', label: 'Country', icon: 'globe', description: 'Country selector' },
      { type: 'heading', label: 'Section / Heading', icon: 'heading', description: 'Divider with a title' },
    ],
  },
]

export const ALL_TYPES = FIELD_CATEGORIES.flatMap((category) => category.types)
const TYPE_MAP = Object.fromEntries(ALL_TYPES.map((entry) => [entry.type, entry]))

export const TYPE_META = (type) => TYPE_MAP[String(type || 'text').toLowerCase()] || {
  type: 'text',
  label: String(type || 'Text'),
  icon: 'text',
  description: '',
}

export const FIELD_ICONS = {
  text: 'M4 7V5h16v2M12 5v14m-3 0h6',
  paragraph: 'M4 6h16M4 10h16M4 14h10M4 18h7',
  mail: 'M3 6h18v12H3zM3 7l9 6 9-6',
  phone: 'M22 16.9v3a2 2 0 01-2.2 2A19.8 19.8 0 012 4.2 2 2 0 014 2h3l2 5-2.2 1.6a16 16 0 007.6 7.6L16 14l5 2z',
  hash: 'M4 9h16M4 15h16M10 3L8 21M16 3l-2 18',
  calendar: 'M3 5h18v16H3zM8 3v4M16 3v4M3 10h18',
  clock: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 7v5l3.5 2',
  'chevron-down': 'M6 9l6 6 6-6',
  radio: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 8a4 4 0 100 8 4 4 0 000-8z',
  'check-square': 'M9 11l3 3 8-8M20 12v7a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h11',
  toggle: 'M8 5h8a7 7 0 010 14H8A7 7 0 018 5zM16 9.5a2.5 2.5 0 010 5',
  upload: 'M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12',
  link: 'M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7',
  'map-pin': 'M19 10c0 5-7 10-7 10S5 15 5 10a7 7 0 1114 0zM12 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
  globe: 'M12 3a9 9 0 100 18 9 9 0 000-18zM3 12h18M12 3c2.5 2.4 4 5.6 4 9s-1.5 6.6-4 9c-2.5-2.4-4-5.6-4-9s1.5-6.6 4-9z',
  heading: 'M6 4v16M18 4v16M6 12h12',
  autocomplete: 'M4 6h16M4 11h10M4 16h13M16 18.5l2 2 4-4',
}

/** Turn a human label into a storable field name, e.g. "First Name" -> "first_name". */
export const slugify = (text) =>
  String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'field'

/** Layout widths offered in the field settings panel. */
export const FIELD_WIDTHS = [
  { value: '25', label: '1/4' },
  { value: '33', label: '1/3' },
  { value: '50', label: '1/2' },
  { value: '100', label: 'Full' },
]

/** Types that let the admin define a list of options. */
export const OPTION_TYPES = ['select', 'radio', 'checkbox']

export const COUNTRIES = [
  'Afghanistan', 'Australia', 'Bangladesh', 'Belgium', 'Brazil', 'Cambodia', 'Canada', 'China',
  'Egypt', 'France', 'Germany', 'India', 'Indonesia', 'Italy', 'Japan', 'Kenya', 'Laos',
  'Malaysia', 'Mexico', 'Myanmar', 'Netherlands', 'New Zealand', 'Nigeria', 'Pakistan',
  'Philippines', 'Russia', 'Saudi Arabia', 'Singapore', 'South Africa', 'South Korea',
  'Spain', 'Sri Lanka', 'Sweden', 'Switzerland', 'Thailand', 'Turkey', 'United Arab Emirates',
  'United Kingdom', 'United States', 'Vietnam', 'Other',
]

/** Default settings payload per field type. */
export const DEFAULT_FIELD_SETTINGS = (type) => {
  switch (type) {
    case 'text': return { min_length: null, max_length: null }
    case 'textarea': return { min_length: null, max_length: null }
    case 'number': return { min_value: null, max_value: null, integer_only: true }
    case 'file': return { allowed_types: ['pdf', 'jpg', 'png'], max_size_mb: 5 }
    default: return {}
  }
}

/** Settings every new field starts with (shared builder + renderer contract). */
export const baseFieldSettings = (type, label) => ({
  ...DEFAULT_FIELD_SETTINGS(type),
  field_name: slugify(label),
  field_name_locked: false,
  width: '100',
  default_value: '',
  hide_label: false,
  multiple: false,
  default_checked: false,
  step: 0,
})

/** Sensible starting point when a field is added. */
export const createField = (type, step = 0) => {
  const meta = TYPE_META(type)
  const label = meta.label === 'Section / Heading' ? 'Section title' : meta.label
  return {
    id: `f${Date.now()}${Math.random().toString(36).slice(2, 6)}`,
    label,
    description: '',
    placeholder: type === 'textarea' ? 'Type your answer…' : '',
    type: meta.type,
    required: false,
    options: OPTION_TYPES.includes(meta.type)
      ? ['Option 1', 'Option 2', 'Option 3']
      : meta.type === 'yesno' ? ['Yes', 'No'] : [],
    settings: { ...baseFieldSettings(meta.type, label), step },
  }
}

/** Create an empty step list for the builder. */
export const createSteps = (count = 1) =>
  Array.from({ length: count }, (_, index) => ({ id: `s${Date.now()}${index}${Math.random().toString(36).slice(2, 4)}`, name: `Step ${index + 1}` }))

/** Fallback starter form for events with no saved fields yet. */
export const starterFields = (step = 0) => [
  { ...createField('text', step), label: 'Full Name', placeholder: 'Enter your full name', required: true },
  { ...createField('email', step), label: 'Email Address', placeholder: 'Enter your email', required: true },
  { ...createField('phone', step), label: 'Phone Number', placeholder: 'Enter your phone number', required: true },
]
