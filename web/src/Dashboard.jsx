import React, { useEffect, useState } from 'react'
import { api, bytes } from './api.js'

const tone = (p) => (p >= 90 ? ' hot' : p >= 75 ? ' warm' : '')
const ago = (s) => { const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600); return d ? d + ' d ' + h + ' h' : h + ' h ' + Math.floor((s % 3600) / 60) + ' min' }

// the running ZARS offer (also for ZARS Panel licences, owner 2026-11-11), read by the server from ZARS Insights at most every 6 hours
export function OfferBanner() {
  const [o, setO] = useState(null)
  useEffect(() => { api('/offer').then((r) => setO(r.offer)).catch(() => {}) }, [])
  if (!o) return null
  const [a, b] = o.colors && o.colors.length ? [o.colors[0], o.colors[1] || o.colors[0]] : ['#4f46e5', '#7c3aed']
  const extra = o.free_months ? 'ZARS Panel licence free for ' + o.free_months + ' months.' : o.discount_pct ? o.discount_pct + '% off the ZARS Panel licence.' : ''
  return (
    <div role="region" aria-label="Offer" className="card" style={{ background: 'linear-gradient(100deg,' + a + ',' + b + ')', color: '#fff', border: 0 }}>
      <strong>{o.emoji} {o.title}</strong>{o.text ? ' - ' + o.text : ''}
      {(extra || o.code) && <div className="small" style={{ marginTop: 4 }}>{extra}{o.code ? ' Code ' : ''}{o.code && <b>{o.code}</b>}</div>}
      <div style={{ marginTop: 8 }}><a href={(o.free_months || o.discount_pct ? o.claim_url : o.cta_url) || o.claim_url || 'https://zarsx.in/contact/'} target="_blank" rel="noopener noreferrer" style={{ color: '#fff', fontWeight: 700, textDecoration: 'underline' }}>{o.free_months ? 'Get it free' : o.discount_pct ? 'Ask for the licence' : (o.cta_label || 'See offer')}</a></div>
    </div>
  )
}

// licence of the full panel (owner 2026-11-11): trial days left or "read-only" - the Community edition has no licence
export function LicenceBanner() {
  const [l, setL] = useState(null)
  useEffect(() => { api('/licence').then(setL).catch(() => {}) }, [])
  if (!l || l.state === 'active' || l.state === 'community') return null
  return (
    <div className={'alert' + (l.state === 'expired' ? ' bad' : '')} role="status">
      {l.message}{l.owner && <>{' '}<a href="#settings/licence" style={{ fontWeight: 700 }}>Enter the licence key</a></>}
      {' '}<a href="https://panel.zarsx.in/#prices" target="_blank" rel="noopener noreferrer">See the prices</a>
    </div>
  )
}

export function Dashboard({ me }) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const load = () => api('/overview').then(setD).catch((e) => setErr(e.message))
  useEffect(() => { load(); const t = setInterval(load, 8000); return () => clearInterval(t) }, [])
  if (err && !d) return <div className="alert bad">{err}</div>
  if (!d) return <p className="muted">Loading...</p>
  const meter = (label, p, sub) => (
    <div className="card"><div className="muted small">{label}</div><div className="big">{p}%</div><div className={'meter' + tone(p)} role="img" aria-label={label + ' ' + p + ' percent'}><i style={{ width: Math.min(100, p) + '%' }} /></div><div className="muted small">{sub}</div></div>
  )
  return (
    <>
      <h1>Overview</h1>
      <div className="muted small" style={{ marginBottom: 12 }}>{d.host} - {d.os} - up {ago(d.uptime)}</div>
      <div className="grid">
        {meter('CPU', d.cpu, d.cores + ' cores, load ' + d.load.join(' / '))}
        {meter('Memory', d.memory.percent, bytes(d.memory.used) + ' of ' + bytes(d.memory.total))}
        {meter('Disk', d.disk.percent, bytes(d.disk.used) + ' of ' + bytes(d.disk.total))}
        <div className="card"><div className="muted small">Network since boot</div><div className="big" style={{ fontSize: '1.2rem' }}>{bytes(d.network.rx)} in</div><div className="muted small">{bytes(d.network.tx)} out</div></div>
      </div>
      {d.services.length > 0 && (
        <div className="card"><h2>Services</h2>
          <div className="row">{d.services.map((s) => <span key={s.name} className={'pill ' + (s.active ? 'on' : 'off')}>{s.name}: {s.active ? 'running' : s.state}</span>)}</div></div>
      )}
      <div className="card"><h2>Your account</h2>
        <div className="row">{Object.entries(d.usage).map(([k, v]) => <span key={k} className="pill">{k}: {v}{me.user.quota[k] != null ? ' of ' + me.user.quota[k] : ''}</span>)}</div></div>
    </>
  )
}
