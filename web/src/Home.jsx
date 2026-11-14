import React, { useEffect, useMemo, useState } from 'react'
import { api, bytes } from './api.js'
import { Icon } from './icons.jsx'
import { OfferBanner, LicenceBanner } from './Dashboard.jsx'
import { GROUPS, FEATURES, canSee, featureOff, TAB_FEATURE } from './features.js'
import { HostingHome, AdminHome } from './HomeModes.jsx'

// Home: the server at a glance, then every feature as a tile in its group (find anything by typing).
export function Home({ me, go, mode }) {
  if (mode === 'user') return <HostingHome me={me} go={go} />
  if (mode === 'admin' || mode === 'reseller') return <AdminHome me={me} go={go} />
  return <ClassicHome me={me} go={go} />
}

function ClassicHome({ me, go }) {
  const [d, setD] = useState(null)
  const [q, setQ] = useState('')
  useEffect(() => {
    const load = () => api('/overview').then(setD).catch(() => {})
    load(); const t = setInterval(load, 10000); return () => clearInterval(t)
  }, [])
  const role = me.user.role
  const off = (me.user.role !== 'owner' && me.user.quota && me.user.quota.off) || []
  const mine = useMemo(() => FEATURES.filter((f) => canSee(f.who, role) && !featureOff(me, f.page) && !(f.siteTab && off.includes(TAB_FEATURE[f.siteTab]))), [role])
  const [news, setNews] = useState([])
  useEffect(() => { if (role !== 'owner') api('/news').then((r) => setNews(r.news)).catch(() => {}) }, [role])
  const words = q.trim().toLowerCase().split(/\s+/).filter(Boolean)
  const shown = words.length ? mine.filter((f) => words.every((w) => (f.name + ' ' + f.text).toLowerCase().includes(w))) : mine
  const hour = new Date().getHours()
  const hello = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
  const stat = (icon, label, value, pct, sub) => (
    <div className="stat">
      <span className="stat-ico"><Icon name={icon} /></span>
      <div className="stat-body">
        <div className="stat-label">{label}</div>
        <div className="stat-value">{value}</div>
        {pct != null && <div className={'meter' + (pct >= 90 ? ' hot' : pct >= 75 ? ' warm' : '')} role="img" aria-label={label + ' ' + pct + ' percent'}><i style={{ width: Math.min(100, pct) + '%' }} /></div>}
        {sub && <div className="stat-sub">{sub}</div>}
      </div>
    </div>
  )
  return (
    <>
      <section className="hero">
        <div>
          <h1>{hello}, {me.user.name}</h1>
          <p className="muted">{d ? d.host + ' - ' + d.os : 'Your server'}{me.user.role === 'owner' && <>{' - '}<span>you are the owner</span></>}</p>
        </div>
        <label className="finder">
          <Icon name="search" />
          <input type="search" placeholder="Find a feature: mail, ssl, backup, php ..." value={q} onChange={(e) => setQ(e.target.value)} aria-label="Find a feature" />
        </label>
      </section>

      <LicenceBanner />
      {role === 'owner' && <OfferBanner />}
      {news.map((n, i) => <div key={i} className="alert" role="status" style={{ whiteSpace: 'pre-wrap' }}>{n.text}</div>)}

      {d && !words.length && (
        <section className="stats">
          {stat('cpu', 'CPU', d.cpu + '%', d.cpu, d.cores + ' cores')}
          {stat('memory', 'Memory', d.memory.percent + '%', d.memory.percent, bytes(d.memory.used) + ' of ' + bytes(d.memory.total))}
          {stat('disk', 'Disk', d.disk.percent + '%', d.disk.percent, bytes(d.disk.used) + ' of ' + bytes(d.disk.total))}
          {stat('globe', 'Your sites', String(d.usage?.sites ?? 0), null, Object.entries(d.usage || {}).filter(([k]) => k !== 'sites').map(([k, v]) => <span key={k}>{v} <span>{k}</span>{' '}</span>))}
        </section>
      )}

      {GROUPS.map(([key, title, icon]) => {
        const items = shown.filter((f) => f.group === key)
        if (!items.length) return null
        return (
          <section key={key} className="group">
            <h2><Icon name={icon} /> {title}</h2>
            <div className="tiles">
              {items.map((f) => (
                <button key={f.name} className="tile" onClick={() => go(f.to)} title={f.siteTab ? 'Open a website, then its "' + f.siteTab + '" tab' : f.text}>
                  <span className="tile-ico"><Icon name={f.icon} /></span>
                  <span className="tile-text"><b>{f.name}</b><small>{f.text}{f.siteTab && <> (<span>per site</span>: <span>{f.siteTab}</span>)</>}</small></span>
                </button>
              ))}
            </div>
          </section>
        )
      })}
      {words.length > 0 && !shown.length && <p className="muted">Nothing found for "{q}". Try another word.</p>}
    </>
  )
}
