import React, { useEffect, useState } from 'react'
import { api } from './api.js'

const APP_COLORS = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#dc2626', '#9333ea', '#0f766e', '#ca8a04']

export function Apps({ me }) {
  const [apps, setApps] = useState(null)
  const [pick, setPick] = useState(null)
  const [res, setRes] = useState(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const load = () => api('/apps').then((r) => setApps(r.apps)).catch((e) => setErr(e.message))
  useEffect(() => { load() }, [])
  return (
    <>
      <h1>App store</h1>{err && <div className="alert bad">{err}</div>}
      <p className="muted">One click installs the newest version with its website and database; apps with their own set-up page show the database details to give it.</p>
      <label style={{ maxWidth: 360 }}>Find an app<input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="shop, photos, tickets ..." /></label>
      {!apps ? <p className="muted">Loading...</p> : [...new Set(apps.map((a) => a.group || 'Websites'))].map((g) => {
        const list = apps.filter((a) => (a.group || 'Websites') === g && (a.name + ' ' + a.about).toLowerCase().includes(q.toLowerCase()))
        return list.length === 0 ? null : <section key={g}><h3>{g}</h3><div className="grid fill">{list.map((a) => (
          <div className="card app-card" key={a.key} style={{ '--c': APP_COLORS[[...a.key].reduce((n, ch) => n + ch.charCodeAt(0), 0) % APP_COLORS.length] }}>
            <div className="app-head"><span className="app-badge" aria-hidden="true">{a.name.slice(0, 1)}</span><h2>{a.name}</h2></div><p className="muted small" style={{ minHeight: 40 }}>{a.about}</p>
            <button className="btn primary" onClick={() => setPick(a)}>Install</button>
          </div>))}</div></section>
      })}
      {pick && <Install app={pick} onClose={() => setPick(null)} onDone={(r) => { setPick(null); setRes(r) }} />}
      {res && <div className="modal" role="dialog" aria-modal="true"><div className="card"><h2>Installed</h2>
        <p>{res.steps.join(' > ')}</p>{res.password && <><p>Database <span className="code">{res.database.name}</span> user <span className="code">{res.database.db_user}</span>, password:</p><p className="code" style={{ padding: 8, fontSize: 16 }}>{res.password}</p><p className="muted small">Copy it now. It is not shown again (the app already has it in its settings).</p></>}
        {res.admin && <><p>Sign in to the app as <span className="code">{res.admin.email}</span> with this password:</p><p className="code" style={{ padding: 8, fontSize: 16 }}>{res.admin.password}</p><p className="muted small">Copy it now and change it after signing in.</p></>}
        {res.notes.map((n, i) => <p key={i}>{n}</p>)}<button className="btn primary" onClick={() => setRes(null)}>Done</button></div></div>}
    </>
  )
}

function Install({ app, onClose, onDone }) {
  const [f, setF] = useState({ domain: '', aliases: '', source: '' })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <div className="modal" role="dialog" aria-modal="true"><form className="card" onSubmit={(e) => { e.preventDefault(); setErr(''); setBusy(true); api('/apps/' + app.key + '/install', f).then(onDone).catch((x) => { setErr(x.message); setBusy(false) }) }}>
      <h2>Install {app.name}</h2>{err && <div className="alert bad" role="alert">{err}</div>}
      <div className="form"><label>Domain<input value={f.domain} onChange={(e) => setF({ ...f, domain: e.target.value })} placeholder="app.example.com" required autoFocus /></label>
        <label>Other names (optional)<input value={f.aliases} onChange={(e) => setF({ ...f, aliases: e.target.value })} /></label>
        {app.group === 'ZARSCO apps' && <label className="full">E-mail of the first administrator (empty: yours)<input type="email" value={f.admin_email || ''} onChange={(e) => setF({ ...f, admin_email: e.target.value })} placeholder="owner@example.com" /></label>}
        {app.custom && <label className="full">Zip file of your app (https address, or a .zip already on this server)<input value={f.source} onChange={(e) => setF({ ...f, source: e.target.value })} placeholder="https://downloads.example.com/myapp.zip" required /></label>}</div>
      <p className="muted small">The domain should already point to this server. You can get the SSL certificate afterwards in Websites.</p>
      <div className="row"><button className="btn primary" disabled={busy}>{busy ? 'Installing (can take a few minutes)...' : 'Install'}</button><button type="button" className="btn" onClick={onClose}>Cancel</button></div>
    </form></div>
  )
}
