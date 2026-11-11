/** A message an admin can act on when loading `what` fails, instead of a bare "Server Error" or a guess. */
export const requestErrorMessage = (error, what) => {
  const response = error?.response
  if (!response) return `Couldn’t reach the server to load ${what}. Check your connection and try again.`
  if (response.status === 403) return `Your account doesn’t have access to ${what}.`
  if (response.status >= 500) return `The server couldn’t load ${what} (error ${response.status}). Try again — if it keeps happening, ask your system administrator to check the server log.`
  return `Couldn’t load ${what}: ${response.data?.message || `error ${response.status}`}.`
}
