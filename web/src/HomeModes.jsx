import React, { useEffect, useMemo, useState } from 'react'
import { api, bytes } from './api.js'
import { Icon } from './icons.jsx'
import { FEATURES, canSee, featureOff, TAB_FEATURE } from './features.js'

const words = (q) => q.trim().toLowerCase().split(/\s+/).filter(Boolean)
const match = (f, w) => w.every((x) => (f.name + ' ' + f.text).toLowerCase().includes(x))

// Hosting account home (cPanel / Webuzo Enduser class): tool groups on the left, the account at a glance on the right.
const USER_GROUPS = [['mail', 'Email', 'mail'], ['files', 'Files', 'folder'], ['db', 'Databases', 'db'], ['web', 'Domains', 'globe'], ['metrics', 'Metrics', 'chart'],
  ['security', 'Security', 'shield'], ['software', 'Software', 'code'], ['advanced', 'Advanced', 'terminal'], ['account', 'Preferences', 'user']]

export function HostingHome({ me, go }) {
  const [q, setQ] = useState('')
  const [s, setS] = useState(null)
  useEffect(() => { api('/account/summary').then(setS).catch(() => {}) }, [])
  const off = (me.user.role !== 'owner' && me.user.quota && me.user.quota.off) || []
  const tools = useMemo(() => FEATURES.filter((f) => f.who === 'any' && !featureOff(me, f.page) && !(f.siteTab && off.includes(TAB_FEATURE[f.siteTab]))), [])
  const w = words(q)
  const shown = w.length ? tools.filter((f) => match(f, w)) : tools
  const fmt = (r) => (r.unit === 'bytes' ? bytes(r.used) : String(r.used)) + ' / ' + (r.limit == null ? '∞' : r.unit === 'bytes' ? bytes(r.limit) : r.limit)
  return (
    <div className="hosting">
      <div className="hosting-main">
        <label className="finder wide"><Icon name="search" /><input type="search" placeholder="Search tools (for example: email, ssl, backup, php)" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search tools" /></label>
        {USER_GROUPS.map(([key, title, icon]) => {
          const items = shown.filter((f) => f.group === key)
          if (!items.length) return null
          return (
            <section key={key} className="cp-group">
              <h2><Icon name={icon} size={18} /> {title}</h2>
              <div className="cp-tiles">
                {items.map((f) => (
                  <button key={f.name} className="cp-tile" onClick={() => go(f.to)} title={f.text}>
                    <span className="cp-ico"><Icon name={f.icon} /></span><span>{f.name}</span>
                  </button>
                ))}
              </div>
            </section>
          )
        })}
        {w.length > 0 && !shown.length && <p className="muted">Nothing found for "{q}".</p>}
      </div>
      <aside className="hosting-side">
        <section className="card">
          <h2>General information</h2>
          {!s ? <p className="muted">Loading...</p> : (
            <dl className="info">
              <dt>Current user</dt><dd>{s.user}</dd>
              <dt>Primary domain</dt><dd>{s.primary_domain || <span className="muted">none yet</span>}</dd>
              {s.plan && <><dt>Package</dt><dd>{s.plan}</dd></>}
              <dt>Shared IP address</dt><dd>{s.ip || '-'}</dd>
              <dt>Home folder</dt><dd className="small" style={{ overflowWrap: 'anywhere' }}>{s.home}</dd>
              <dt>Linux user</dt><dd>{s.linux_user}</dd>
              <dt>Last sign-in from</dt><dd>{s.last_login ? s.last_login.ip + ' (' + s.last_login.at.slice(0, 16).replace('T', ' ') + ')' : '-'}</dd>
            </dl>
          )}
        </section>
        <section className="card">
          <h2>Statistics</h2>
          {s && s.stats.map((r) => {
            const pct = r.limit ? Math.min(100, Math.round((r.used / r.limit) * 100)) : null
            return (
              <div key={r.key} className="cp-stat">
                <div className="row-between"><span>{r.label}</span><b>{fmt(r)}</b></div>
                {pct != null && <div className={'meter' + (pct >= 90 ? ' hot' : pct >= 75 ? ' warm' : '')}><i style={{ width: pct + '%' }} /></div>}
              </div>
            )
          })}
        </section>
      </aside>
    </div>
  )
}

// Server admin home (WHM / Webuzo Admin class): every function in its category as a plain link, one search over all of them.
export const ADMIN_GROUPS = [['clients', 'Account functions'], ['server', 'Server configuration'], ['web', 'Domains, DNS and SSL'], ['db', 'SQL services'], ['mail', 'Email'],
  ['security', 'Security center'], ['metrics', 'Server status and logs'], ['software', 'Software and PHP'], ['advanced', 'Service configuration'], ['files', 'Backups and transfers'], ['account', 'My account']]

export function AdminHome({ me, go }) {
  const [q, setQ] = useState('')
  const [d, setD] = useState(null)
  useEffect(() => { const l = () => api('/overview').then(setD).catch(() => {}); l(); const t = setInterval(l, 10000); return () => clearInterval(t) }, [])
  const role = me.user.role
  const mine = useMemo(() => FEATURES.filter((f) => canSee(f.who, role) && !featureOff(me, f.page)), [role])
  const w = words(q)
  const shown = w.length ? mine.filter((f) => match(f, w)) : mine
  const meter = (label, pct, sub) => <div className="adm-stat"><div className="row-between"><span>{label}</span><b>{pct}%</b></div><div className={'meter' + (pct >= 90 ? ' hot' : pct >= 75 ? ' warm' : '')}><i style={{ width: Math.min(100, pct) + '%' }} /></div><small className="muted">{sub}</small></div>
  return (
    <>
      <section className="adm-head">
        <div><h1>{role === 'owner' ? 'Server admin' : 'Reseller center'}</h1><p className="muted">{d ? d.host + ' - ' + d.os : ''}</p></div>
        <label className="finder"><Icon name="search" /><input type="search" placeholder="Find a function (create account, php, firewall ...)" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Find a function" autoFocus /></label>
      </section>
      {d && !w.length && role === 'owner' && (
        <section className="adm-stats">
          {meter('CPU', d.cpu, d.cores + ' cores')}{meter('Memory', d.memory.percent, bytes(d.memory.used) + ' of ' + bytes(d.memory.total))}{meter('Disk', d.disk.percent, bytes(d.disk.used) + ' of ' + bytes(d.disk.total))}
          <div className="adm-stat adm-quick"><button className="btn primary sm" onClick={() => go('users')}><Icon name="users" size={16} /> Create account</button><button className="btn sm" onClick={() => go('users')}>List accounts</button><button className="btn sm" onClick={() => go('plans')}>Packages</button></div>
        </section>
      )}
      <div className="fn-cols">
        {ADMIN_GROUPS.map(([key, title]) => {
          const items = shown.filter((f) => f.group === key)
          if (!items.length) return null
          return (
            <section key={key} className="fn-card">
              <h2>{title}</h2>
              <ul>{items.map((f) => <li key={f.name}><button onClick={() => go(f.to)} title={f.text}><Icon name={f.icon} size={15} />{f.name}</button></li>)}</ul>
            </section>
          )
        })}
      </div>
      {w.length > 0 && !shown.length && <p className="muted">Nothing found for "{q}".</p>}
    </>
  )
}
