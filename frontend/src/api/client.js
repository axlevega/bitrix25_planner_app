/**
 * Базовый клиент запросов к backend API.
 * Base URL задаётся через VITE_API_BASE_URL в .env.
 */
const baseURL = import.meta.env.VITE_API_BASE_URL || '/api'

async function request(path, options = {}) {
  const url = path.startsWith('http') ? path : `${baseURL.replace(/\/$/, '')}${path.startsWith('/') ? path : `/${path}`}`
  const config = {
    headers: {
      'Content-Type': 'application/json',
      ...options.headers,
    },
    ...options,
  }
  if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
    config.body = JSON.stringify(config.body)
  }
  const res = await fetch(url, config)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.message || data.error || `HTTP ${res.status}`)
  }
  if (data.error && typeof data.error === 'string') {
    throw new Error(data.error)
  }
  return data
}

function buildPath(path, params) {
  if (!params || Object.keys(params).length === 0) return path
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v != null && v !== '') q.set(k, v)
  }
  const qs = q.toString()
  return qs ? `${path}?${qs}` : path
}

export const api = {
  get: (path, params) => request(buildPath(path, params), { method: 'GET' }),
  post: (path, body) => request(path, { method: 'POST', body }),
  put: (path, body) => request(path, { method: 'PUT', body }),
  patch: (path, body) => request(path, { method: 'PATCH', body }),
  delete: (path) => request(path, { method: 'DELETE' }),
  // Справочники и интеграция
  departments: {
    list: () => api.get('/departments'),
    get: (id) => api.get('/departments', { id }),
    create: (data) => api.post('/departments', data),
    update: (data) => api.put('/departments', data),
  },
  specialists: {
    list: () => api.get('/specialists'),
    get: (id) => api.get('/specialists', { id }),
    create: (data) => api.post('/specialists', data),
    update: (data) => api.put('/specialists', data),
  },
  integrationSettings: {
    get: () => api.get('/integration-settings'),
    save: (data) => api.post('/integration-settings', data),
  },
  sync: () => api.post('/sync'),
}

export default api
