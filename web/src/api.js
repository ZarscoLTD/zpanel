let csrf = ''
export const setCsrf = (v) => { csrf = v }

export async function api(path, body, opts = {}) {
  const res = await fetch('/api' + path, {
    method: body === undefined && !opts.method ? 'GET' : 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), 'X-CSRF-Token': csrf },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  let data = {}
  try { data = await res.json() } catch (e) { /* not JSON */ }
  if (res.status === 401 && path !== '/login' && path !== '/me') window.dispatchEvent(new Event('zp-signed-out'))
  if (!res.ok && res.status !== 202) { const err = new Error(data.message || 'Something went wrong (' + res.status + ')'); err.status = res.status; throw err }
  return Object.assign(data, { _status: res.status })
}

export async function upload(path, files) {
  const fd = new FormData()
  for (const f of files) fd.append('files[]', f)
  const res = await fetch('/api' + path, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, body: fd })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Upload failed')
  return data
}

export const bytes = (n) => { if (!n && n !== 0) return '-'; const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++ } return n.toFixed(i ? 1 : 0) + ' ' + u[i] }
