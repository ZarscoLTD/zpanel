import React, { useEffect, useMemo, useRef, useState } from 'react'
import { api, bytes } from './api.js'
import { Icon } from './icons.jsx'
import { OfferBanner, LicenceBanner } from './Dashboard.jsx'
import { SECTIONS, sectionOf, FEATURES, canSee, featureOff, TAB_FEATURE } from './features.js'

// The look of 2026-11-14 (owner: "nobody understands the panel"): twelve sections in the menu like aaPanel / CyberPanel, every
// section a page of tool cards (coloured icon, name, one line), a dashboard with live numbers and quick actions, one search for all.

/** The tools this person may use in this panel mode. */
export function toolsFor(me, mode) {
  const role = me.user.role
  const off = (role !== 'owner' && me.user.quota && me.user.quota.off) || []
  return FEATURES.filter((f) => (mode === 'user' ? f.who === 'any' : canSee(f.who, role)) && !featureOff(me, f.page) && !(f.siteTab && off.includes(TAB_FEATURE[f.siteTab])))
}
export function useTools(me, mode) {
  return useMemo(() => toolsFor(me, mode), [me.user.role, mode])
}

const words = (q) => q.trim().toLowerCase().split(/\s+/).filter(Boolean)
const hits = (tools, q) => { const w = words(q); return w.length ? tools.filter((f) => w.every((x) => (f.name + ' ' + f.text + ' ' + (sectionOf(f.group) || [])[1]).toLowerCase().includes(x))) : tools }

/** Which section a route belongs to (for the menu mark and the breadcrumb). */
export function sectionForRoute(route, tools) {
  if (!route || route === 'home') return 'home'
  if (route.startsWith('s/')) return route.slice(2)
  const exact = tools.find((f) => f.to === route)
  if (exact) return exact.group
  const page = route.split('/')[0]
  const same = tools.find((f) => f.to.split('/')[0] === page)
  return same ? same.group : 'home'
}

export function ToolCard({ f, go }) {
  const s = sectionOf(f.group) || []
  return (
    <button className="tool-card" onClick={() => go(f.to)} style={{ '--c': s[3] }}>
      <span className="tool-ico"><Icon name={f.icon} /></span>
      <span className="tool-text"><b>{f.name}</b><small>{f.text}</small>{f.siteTab && <em className="chip">per website</em>}</span>
    </button>
  )
}

/** Search over every tool (menu and dashboard): results as a list under the box. */
export function FindTool({ tools, go, placeholder, big }) {
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const box = useRef(null)
  const found = q.trim() ? hits(tools, q).slice(0, 12) : []
  useEffect(() => {
    const out = (e) => { if (box.current && !box.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', out); return () => document.removeEventListener('mousedown', out)
  }, [])
  const pick = (f) => { setQ(''); setOpen(false); go(f.to) }
  return (
    <div className={'find-tool' + (big ? ' big' : '')} ref={box}>
      <label><Icon name="search" size={big ? 20 : 16} />
        <input type="search" value={q} placeholder={placeholder || 'Search tools'} aria-label="Search tools" onFocus={() => setOpen(true)}
          onChange={(e) => { setQ(e.target.value); setOpen(true) }} onKeyDown={(e) => { if (e.key === 'Enter' && found[0]) pick(found[0]); if (e.key === 'Escape') setOpen(false) }} />
      </label>
      {open && q.trim() !== '' && (
        <div className="find-list" role="listbox">
          {found.length === 0 ? <p className="muted small" style={{ padding: '8px 12px', margin: 0 }}>Nothing found. Try another word.</p> : found.map((f) => {
            const s = sectionOf(f.group) || []
            return <button key={f.name + f.to} role="option" onClick={() => pick(f)} style={{ '--c': s[3] }}><span className="find-ico"><Icon name={f.icon} size={16} /></span><span><b>{f.name}</b><small>{s[1]}</small></span></button>
          })}
        </div>
      )}
    </div>
  )
}

/** The menu: dashboard + the sections that have tools for this person. */
export function SideNav({ tools, go, current }) {
  return (
    <nav className="nav sec-nav" aria-label="Main menu">
      <FindTool tools={tools} go={go} placeholder="Search tools" />
      {SECTIONS.map(([key, title, icon, color, , short]) => {
        const n = key === 'home' ? null : tools.filter((f) => f.group === key).length
        if (n === 0) return null
        return (
          <button key={key} aria-current={current === key ? 'page' : undefined} onClick={() => go(key === 'home' ? 'home' : 's/' + key)} style={{ '--c': color }}>
            <span className="nav-ico"><Icon name={icon} size={17} /></span><span title={title}>{short || title}</span>{n != null && <small className="nav-count">{n}</small>}
          </button>
        )
      })}
    </nav>
  )
}

/** One section: big header, then all its tools as cards. */
export function SectionPage({ me, go, tab, mode }) {
  const tools = useTools(me, mode)
  const s = sectionOf(tab)
  const [q, setQ] = useState('')
  if (!s || tab === 'home') return <p className="muted">This section does not exist.</p>
  const mine = tools.filter((f) => f.group === tab)
  const shown = hits(mine, q)
  const whole = mine.filter((f) => !f.siteTab)
  const perSite = mine.filter((f) => f.siteTab)
  const grid = (list) => <div className="tool-grid">{list.map((f) => <ToolCard key={f.name + f.to} f={f} go={go} />)}</div>
  return (
    <>
      <section className="sec-head" style={{ '--c': s[3] }}>
        <span className="sec-ico"><Icon name={s[2]} size={28} /></span>
        <div><h1>{s[1]}</h1><p className="muted">{s[4]} - {mine.length} tools</p></div>
        {mine.length > 8 && <label className="finder"><Icon name="search" /><input type="search" placeholder={'Search in ' + s[1].toLowerCase()} value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search in this section" /></label>}
      </section>
      {q.trim() ? (shown.length ? grid(shown) : <p className="muted">Nothing found for "{q}".</p>) : <>
        {whole.length > 0 && grid(whole)}
        {perSite.length > 0 && <><h2 className="sub-head">For one website <small className="muted">(pick the website on the next page)</small></h2>{grid(perSite)}</>}
      </>}
    </>
  )
}

const QUICK = [
  ['users', 'Create account', 'users', 'reseller'], ['sites', 'Add website', 'globe', 'any'], ['apps', 'Install an app', 'store', 'any'],
  ['mail', 'Add mailbox', 'mail', 'any'], ['databases', 'Add database', 'db', 'any'], ['backups', 'Back up now', 'archive', 'any'],
]

/** Dashboard of every panel mode: live numbers, quick actions, the sections as big cards. */
export function DashboardHome({ me, go, mode }) {
  const tools = useTools(me, mode)
  const role = me.user.role
  const [d, setD] = useState(null)
  const [news, setNews] = useState([])
  const [acct, setAcct] = useState(null)
  useEffect(() => { if (mode === 'user') api('/account/summary').then(setAcct).catch(() => {}) }, [mode])
  useEffect(() => { const l = () => api('/overview').then(setD).catch(() => {}); l(); const t = setInterval(l, 10000); return () => clearInterval(t) }, [])
  useEffect(() => { if (role !== 'owner') api('/news').then((r) => setNews(r.news || [])).catch(() => {}) }, [role])
  const hour = new Date().getHours()
  const hello = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
  const pages = new Set(tools.map((f) => f.page))
  const quick = QUICK.filter(([page, , , who]) => pages.has(page) && (who === 'any' || (mode !== 'user' && role !== 'client')))
  const stat = (icon, color, label, value, pct, sub) => (
    <div className="dash-stat" style={{ '--c': color }}>
      <span className="dash-ico"><Icon name={icon} /></span>
      <div><div className="stat-label">{label}</div><div className="stat-value">{value}</div>
        {pct != null && <div className={'meter' + (pct >= 90 ? ' hot' : pct >= 75 ? ' warm' : '')}><i style={{ width: Math.min(100, pct) + '%' }} /></div>}
        {sub && <div className="stat-sub">{sub}</div>}</div>
    </div>
  )
  const panelName = mode === 'user' ? 'your hosting' : role === 'owner' ? 'your server' : 'your reseller center'
  return (
    <>
      <section className="dash-hero">
        <div><h1>{hello}, {me.user.name}</h1><p>Everything for {panelName} in one place{d ? ' - ' + d.host : ''}. {tools.length} tools.</p></div>
        <FindTool tools={tools} go={go} big placeholder="What do you want to do? (email, ssl, backup, php ...)" />
      </section>
      <LicenceBanner />
      {role === 'owner' && mode !== 'user' && <OfferBanner />}
      {news.map((n, i) => <div key={i} className="alert" role="status" style={{ whiteSpace: 'pre-wrap' }}>{n.text}</div>)}
      {mode === 'user' && acct && <>
        <section className="dash-stats">
          {acct.stats.slice(0, 4).map((r, i) => {
            const pct = r.limit ? Math.min(100, Math.round((r.used / r.limit) * 100)) : null
            const fmt = (v) => (r.unit === 'bytes' ? bytes(v) : String(v))
            return <React.Fragment key={r.key}>{stat(['disk', 'globe', 'db', 'mail'][i] || 'chart', ['#ea580c', '#16a34a', '#7c3aed', '#db2777'][i] || '#4f46e5', r.label, fmt(r.used), pct, r.limit == null ? 'no limit' : 'of ' + fmt(r.limit))}</React.Fragment>
          })}
        </section>
        <p className="acct-info">
          <span>User <b>{acct.user}</b></span>{acct.primary_domain && <span>Main domain <b>{acct.primary_domain}</b></span>}{acct.plan && <span>Plan <b>{acct.plan}</b></span>}
          {acct.ip && <span>IP address <b>{acct.ip}</b></span>}{acct.last_login && <span>Last sign-in <b>{acct.last_login.ip}</b></span>}
        </p>
      </>}
      {d && mode !== 'user' && (
        <section className="dash-stats">
          {stat('cpu', '#4f46e5', 'CPU', d.cpu + '%', d.cpu, d.cores + ' cores')}
          {stat('memory', '#0891b2', 'Memory', d.memory.percent + '%', d.memory.percent, bytes(d.memory.used) + ' of ' + bytes(d.memory.total))}
          {stat('disk', '#ea580c', 'Disk', d.disk.percent + '%', d.disk.percent, bytes(d.disk.used) + ' of ' + bytes(d.disk.total))}
          {stat('globe', '#16a34a', 'Websites', String(d.usage?.sites ?? 0), null, Object.entries(d.usage || {}).filter(([k]) => k !== 'sites').map(([k, v]) => v + ' ' + k).join(', ') || null)}
        </section>
      )}
      {quick.length > 0 && <>
        <h2 className="sub-head">Quick actions</h2>
        <section className="quick-row">{quick.map(([page, label, icon]) => <button key={page} className="quick" onClick={() => go(page)}><span className="quick-ico"><Icon name={icon} /></span>{label}</button>)}</section>
      </>}
      <h2 className="sub-head">Everything in {me.brand.name}</h2>
      <section className="sec-grid">
        {SECTIONS.filter(([k]) => k !== 'home').map(([key, title, icon, color, line]) => {
          const list = tools.filter((f) => f.group === key)
          if (!list.length) return null
          return (
            <div key={key} className="sec-card" style={{ '--c': color }}>
              <button className="sec-card-head" onClick={() => go('s/' + key)}>
                <span className="sec-ico"><Icon name={icon} size={22} /></span>
                <span><b>{title}</b><small>{line}</small></span><em className="chip">{list.length}</em>
              </button>
              <div className="sec-links">{list.slice(0, 5).map((f) => <button key={f.name + f.to} onClick={() => go(f.to)}>{f.name}</button>)}
                {list.length > 5 && <button className="more" onClick={() => go('s/' + key)}>All {list.length} tools</button>}</div>
            </div>
          )
        })}
      </section>
    </>
  )
}
