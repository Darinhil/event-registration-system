/**
 * Conditional questions: settings.show_if = { field: <source field id>, equals: <answer> }
 * asks a question only when an earlier single-choice question got that answer.
 * Mirrors backend/app/Support/FormConditions.php so the builder preview, the live
 * form and server validation agree on which questions were asked.
 */

/** Single-answer questions whose answer can drive a condition. */
export const isConditionSource = (field) =>
  ['radio', 'yesno'].includes(field?.type) || (field?.type === 'select' && !field.settings?.multiple)

export const conditionAnswers = (field) => (field?.type === 'yesno' ? ['Yes', 'No'] : (field?.options || []).filter((option) => String(option).trim()))

export const conditionOf = (field) => {
  const condition = field?.settings?.show_if
  return condition && condition.field !== undefined && condition.field !== null && condition.field !== '' ? condition : null
}

export const isFieldVisible = (field, fields, values, depth = 0) => {
  const condition = conditionOf(field)
  if (!condition || depth > 10) return true
  // A condition on a question that no longer exists never hides anything.
  const source = fields.find((entry) => String(entry.id) === String(condition.field))
  if (!source) return true
  if (!isFieldVisible(source, fields, values, depth + 1)) return false
  const answer = values?.[source.id]
  return Array.isArray(answer) ? answer.map(String).includes(String(condition.equals)) : String(answer ?? '') === String(condition.equals)
}
