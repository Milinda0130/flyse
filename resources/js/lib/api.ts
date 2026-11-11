import axios, { AxiosError } from 'axios'

const TOKEN_KEY = 'ftms_token'

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

export const api = axios.create({
  // Same origin: Laravel serves both the React app and the API.
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = tokenStore.get()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void) {
  onUnauthorized = fn
}

api.interceptors.response.use(
  (r) => r,
  (error: AxiosError) => {
    if (error.response?.status === 401 && onUnauthorized) onUnauthorized()
    return Promise.reject(error)
  },
)

export type ValidationErrors = Record<string, string[]>

/** Human-readable message from an API error. */
export function errorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: ValidationErrors } | undefined
    if (error.response?.status === 403) return data?.message && data.message !== 'This action is unauthorized.' ? data.message : 'You do not have permission to do this.'
    if (error.response?.status === 413) return 'The upload is too large.'
    if (error.response?.status === 429) return 'Too many attempts. Please wait a minute and try again.'
    if (data?.errors) return Object.values(data.errors).flat()[0] ?? 'Please check the form.'
    if (data?.message) return data.message
    if (!error.response) return 'Cannot reach the server. Check your connection.'
  }
  return 'Something went wrong.'
}

export function fieldErrors(error: unknown): ValidationErrors {
  if (axios.isAxiosError(error) && error.response?.status === 422) {
    return (error.response.data as { errors?: ValidationErrors }).errors ?? {}
  }
  return {}
}

/** Downloads (or opens) a private file through the authorised API. */
export async function downloadFile(url: string, filename: string, inline = false) {
  const response = await api.get(url, { responseType: 'blob', params: inline ? { inline: 1 } : undefined })
  const blobUrl = URL.createObjectURL(response.data as Blob)
  if (inline) {
    window.open(blobUrl, '_blank', 'noopener')
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)
    return
  }
  const a = document.createElement('a')
  a.href = blobUrl
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(blobUrl)
}

/** Removes empty values so they are not sent as query parameters. */
export function cleanParams<T extends Record<string, unknown>>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined)) as Partial<T>
}
