import { SideNav, SectionPage, DashboardHome, toolsFor, sectionForRoute } from './Sections.jsx'
import { NetworkTools, SshKeys, DiskUsage, RemoteDb, Bandwidth, AccountsUsage } from './AccountTools.jsx'
import React, { useEffect, useState } from 'react'
import { api, setCsrf } from './api.js'
import { Dashboard } from './Dashboard.jsx'
import { Icon } from './icons.jsx'
import { Sites, SiteTool } from './Sites.jsx'
import { Databases, Cron, Backups } from './Ops.jsx'
import { Dns, Ftp, Redis, Docker, Mail, Terminal } from './Server.jsx'
import { Apps } from './Apps.jsx'
import { Firewall, Users, Security, Settings, ResellerBrand } from './Admin.jsx'
import { ServerTools, Plans, Tickets, ImportCpanel } from './Tools.jsx'
import { Uptime, MoveFromServer, Cloudflare } from './Plus.jsx'
import { featureOff, FEATURES, canSee, toolSlug, sectionOf } from './features.js'
import { SellHosting } from './Billing.jsx'
import { DialogHost } from './dialog.jsx'
import { LANGUAGES, language, setLanguage, savedLanguage } from './i18n.js'

// the language picker (sign-in page and the user menu)
function LanguagePick({ onPick }) {
  const [cur, setCur] = useState(language())
  return (
    <label className="lang-pick"><span>Language</span>
      <select value={cur} onChange={(e) => { const c = e.target.value; setCur(c); setLanguage(c); if (onPick) onPick(c) }} data-no-translate>
        {Object.entries(LANGUAGES).map(([code, name]) => <option key={code} value={code}>{name}</option>)}
      </select>
    </label>
  )
}

function Login({ onDone, brand }) {
  const [f, setF] = useState({ name: '', password: '', code: '' })
  const [two, setTwo] = useState(false)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [sso, setSso] = useState(false)
  useEffect(() => {
    api('/sso/info').then((r) => setSso(!!r.enabled)).catch(() => {})
    const m = new URLSearchParams(window.location.search).get('sso_error')
    if (m) setErr(m)
  }, [])
  const go = (e) => {
    e.preventDefault(); setErr(''); setBusy(true)
    api('/login', f).then((r) => { if (r.two_step) { setTwo(true); return } setCsrf(r.csrf); onDone() }).catch((x) => setErr(x.message)).finally(() => setBusy(false))
  }
  return (
    <div className="login">
      <form className="card" onSubmit={go}>
        {brand.logo && <img src={brand.logo} alt="" style={{ maxHeight: 48, maxWidth: 200, display: 'block', marginBottom: 8 }} />}
        <h1 style={{ marginBottom: 4 }}>{brand.name}</h1>
        <p className="muted small" style={{ marginTop: 0 }}>Sign in to manage this server.</p>
        {err && <div className="alert bad" role="alert">{err}</div>}
        <div style={{ display: 'grid', gap: 12 }}>
          <label>User name<input autoFocus autoComplete="username" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></label>
          <label>Password<input type="password" autoComplete="current-password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} required /></label>
          {two && <label>Two-step code<input inputMode="numeric" autoComplete="one-time-code" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} placeholder="6 digits from your authenticator app" required /></label>}
          <button className="btn primary" disabled={busy}>{busy ? 'Signing in...' : 'Sign in'}</button>
          {sso && <a className="btn" href="/api/sso/start" style={{ textAlign: 'center' }}>Sign in with your company account</a>}
          <LanguagePick />
        </div>
      </form>
    </div>
  )
}

// Panel 2.0: three panels like WHM + cPanel. "admin" = server admin (owner), "reseller" = reseller, "user" = hosting account (cPanel).
// The owner and resellers switch to "My hosting" (their own hosting account) and back.
const USER_NAV = [
  ['', [['home', 'Home', 'home', 'any']]],
  ['Websites and domains', [['sites', 'Websites and domains', 'globe', 'any'], ['apps', 'App installer', 'store', 'any'], ['dns', 'Zone editor (DNS)', 'dns', 'any'], ['cloudflare', 'Cloudflare', 'shield', 'any']]],
  ['Files', [['backups', 'Backups', 'archive', 'any'], ['ftp', 'FTP accounts', 'upload', 'any'], ['diskusage', 'Disk usage', 'disk', 'any']]],
  ['Databases', [['databases', 'MySQL / PostgreSQL', 'db', 'any'], ['remotedb', 'Remote MySQL', 'db', 'any'], ['redis', 'Redis', 'bolt', 'any']]],
  ['Email', [['mail', 'Email accounts', 'mail', 'any']]],
  ['Advanced', [['cron', 'Cron jobs', 'clock', 'any'], ['uptime', 'Uptime monitor', 'chart', 'any'], ['migrate', 'Move a site here', 'move', 'any'], ['nettools', 'Network tools', 'network', 'any'], ['sshkeys', 'SSH access', 'key', 'any']]],
  ['Account', [['overview', 'Resource usage', 'cpu', 'any'], ['bandwidth', 'Bandwidth', 'chart', 'any'], ['security', 'Password and security', 'user', 'any'], ['tickets', 'Support', 'ticket', 'any']]],
]
const RESELLER_NAV = [
  ['', [['home', 'Home', 'home', 'any']]],
  ['Account functions', [['users', 'Create / list accounts', 'users', 'reseller'], ['usage', 'Accounts resource usage', 'chart', 'reseller'], ['plans', 'Packages and features', 'tag', 'reseller'], ['import', 'Transfer from cPanel / Webuzo', 'move', 'reseller'], ['billing', 'Billing (WHMCS, Blesta)', 'store', 'reseller']]],
  ['Help', [['tickets', 'Support tickets', 'ticket', 'any'], ['security', 'My account', 'user', 'any']]],
]
const NAV = [
  ['', [['home', 'Home', 'home', 'any']]],
  ['Accounts', [['users', 'Accounts (users, resellers)', 'users', 'reseller'], ['plans', 'Hosting plans', 'tag', 'reseller'], ['usage', 'Accounts resource usage', 'chart', 'reseller'], ['branding', 'Your brand', 'palette', 'reselleronly']]],
  ['Websites', [['sites', 'Websites', 'globe', 'any'], ['apps', 'App store', 'store', 'any'], ['dns', 'DNS zones', 'dns', 'any'], ['cloudflare', 'Cloudflare', 'shield', 'any'], ['uptime', 'Uptime monitor', 'chart', 'any']]],
  ['Files and data', [['backups', 'Backups', 'archive', 'any'], ['ftp', 'FTP users', 'upload', 'any'], ['databases', 'Databases', 'db', 'any'], ['redis', 'Redis', 'bolt', 'any']]],
  ['Email and jobs', [['mail', 'Email', 'mail', 'any'], ['cron', 'Cron jobs', 'clock', 'any']]],
  ['Server', [['overview', 'Server status', 'cpu', 'any'], ['tools', 'Server tools', 'server', 'owner'], ['firewall', 'Firewall and SSH', 'shield', 'owner'], ['terminal', 'Terminal', 'terminal', 'owner'], ['docker', 'Docker', 'docker', 'owner'], ['settings', 'Settings', 'cog', 'owner']]],
  ['Move and sell', [['import', 'Move from cPanel / Webuzo / DirectAdmin / Plesk', 'move', 'reseller'], ['migrate', 'Move from another server', 'move', 'any'], ['billing', 'Sell hosting (WHMCS, Blesta)', 'store', 'reseller']]],
  ['Help', [['tickets', 'Support tickets', 'ticket', 'any'], ['security', 'My account', 'user', 'any']]],
  ['', [['nettools', 'Network tools', 'network', 'any'], ['sshkeys', 'SSH access', 'key', 'any'], ['diskusage', 'Disk usage', 'disk', 'any'], ['remotedb', 'Remote MySQL', 'db', 'any'], ['bandwidth', 'Bandwidth', 'chart', 'any']]],
]
const VIEWS = { home: DashboardHome, s: SectionPage, overview: Dashboard, sites: Sites, apps: Apps, databases: Databases, dns: Dns, ftp: Ftp, mail: Mail, redis: Redis, docker: Docker, terminal: Terminal, cron: Cron, backups: Backups, firewall: Firewall, tools: ServerTools, plans: Plans, import: ImportCpanel, tickets: Tickets, users: Users, security: Security, settings: Settings, uptime: Uptime, migrate: MoveFromServer, cloudflare: Cloudflare, billing: SellHosting, nettools: NetworkTools, sshkeys: SshKeys, diskusage: DiskUsage, remotedb: RemoteDb, bandwidth: Bandwidth, usage: AccountsUsage, branding: ResellerBrand }

// light / dark / follow the computer, remembered on this browser
function useTheme() {
  const [t, setT] = useState(() => { try { return localStorage.getItem('zp-theme') || 'auto' } catch (e) { return 'auto' } })
  useEffect(() => {
    if (t === 'auto') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = t
    try { localStorage.setItem('zp-theme', t) } catch (e) { /* private window */ }
  }, [t])
  const dark = t === 'dark' || (t === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
  return [dark, () => setT(dark ? 'light' : 'dark')]
}

export default function App() {
  const [me, setMe] = useState(undefined)
  const [route, setRoute] = useState(() => window.location.hash.slice(1) || 'home')
  const [open, setOpen] = useState(false)
  const [userMenu, setUserMenu] = useState(false)
  const [pub, setPub] = useState({ name: 'ZARS Panel' })
  const [dark, flipTheme] = useTheme()
  useEffect(() => { setLanguage(savedLanguage()) }, [])
  useEffect(() => { api('/brand').then((b) => { setPub(b); document.title = b.name; if (b.color) document.documentElement.style.setProperty('--brand', b.color) }).catch(() => {}) }, [])
  const load = () => api('/me').then((r) => { setCsrf(r.csrf); setMe(r) }).catch(() => setMe(null))
  useEffect(() => { load() }, [])
  useEffect(() => {
    const out = () => setMe(null)
    const hash = () => { setRoute(window.location.hash.slice(1) || 'home'); setOpen(false); window.scrollTo(0, 0) }
    window.addEventListener('zp-signed-out', out); window.addEventListener('hashchange', hash)
    return () => { window.removeEventListener('zp-signed-out', out); window.removeEventListener('hashchange', hash) }
  }, [])
  useEffect(() => { if (me) { document.documentElement.style.setProperty('--brand', me.brand.color); document.title = me.brand.name } }, [me])
  useEffect(() => { if (me && me.user.language) setLanguage(me.user.language) }, [me])
  if (me === undefined) return <div className="login"><p className="muted">Loading...</p></div>
  if (me === null) return <Login onDone={load} brand={pub} />
  const role = me.user.role
  const savedMode = (() => { try { return localStorage.getItem('zp-mode') } catch (e) { return null } })()
  const mode = role === 'client' ? 'user' : savedMode === 'user' ? 'user' : role === 'owner' ? 'admin' : 'reseller'
  const switchMode = () => { try { localStorage.setItem('zp-mode', mode === 'user' ? 'admin' : 'user') } catch (e) { /* private window */ } window.location.hash = 'home'; window.location.reload() }
  const MENU = mode === 'user' ? USER_NAV : mode === 'reseller' ? RESELLER_NAV : NAV
  const allowed = (a) => a === 'any' || (a === 'owner' && role === 'owner') || (a === 'reseller' && role !== 'client') || (a === 'reselleronly' && role === 'reseller')
  const [pageRaw, tab] = route.split('/')
  const page = pageRaw === 'dashboard' ? 'overview' : pageRaw
  const items = MENU.flatMap(([, list]) => list).filter(([k, , , a]) => allowed(a) && !featureOff(me, k))
  const toolFeature = page === 'tool' ? FEATURES.find((f) => f.siteTab && toolSlug(f.name) === tab && canSee(f.who, role)) : null
  const tools = toolsFor(me, mode)
  const open2 = new Set([...items.map(([k]) => k), ...tools.map((f) => f.page)]) // pages of the menu and of every visible tool
  const cur = toolFeature ? 'tool' : page === 's' && sectionOf(tab) ? 's' : open2.has(page) && VIEWS[page] ? page : 'home'
  const secKey = sectionForRoute(cur === 'home' ? 'home' : route, tools)
  const sec = sectionOf(secKey)
  const named = tools.find((f) => f.to === route)
  const title = toolFeature ? toolFeature.name : cur === 's' ? '' : named ? named.name : (items.find(([k]) => k === cur) || [])[1] || 'Home'
  const go = (k) => { window.location.hash = k; setOpen(false); setUserMenu(false) }
  const logout = () => api('/logout', {}).finally(() => setMe(null))
  const View = VIEWS[cur]
  return (
    <div className={'layout mode-' + mode + (open ? ' nav-open' : '')}>
      <aside className="side" aria-label="Main menu">
        <div className="brand">{me.brand.logo ? <img src={me.brand.logo} alt="" /> : <span className="brand-mark">{(me.brand.name || 'Z').slice(0, 1)}</span>}<span>{me.brand.name}<small className="mode-name">{mode === 'user' ? 'Hosting' : mode === 'reseller' ? 'Reseller' : 'Server admin'}</small></span></div>
        <SideNav tools={tools} go={go} current={secKey} />
        <p className="side-foot">Version {me.version}</p>
      </aside>
      <div className="scrim" onClick={() => setOpen(false)} aria-hidden="true" />
      <div className="content">
        <header className="topbar">
          <button className="icon-btn menu-btn" aria-label="Open menu" aria-expanded={open} onClick={() => setOpen(!open)}><Icon name="menu" /></button>
          <div className="crumb"><button className="crumb-link" onClick={() => go('home')}>{me.brand.name}</button>
            {sec && secKey !== 'home' && <><span className="sep">/</span>{title ? <button className="crumb-link" onClick={() => go('s/' + secKey)}>{sec[1]}</button> : <b>{sec[1]}</b>}</>}
            {title && cur !== 'home' && <><span className="sep">/</span><b>{title}</b></>}</div>
          <div className="top-actions">
            {role !== 'client' && <button className="btn sm mode-switch" onClick={switchMode} title="Switch panel"><span className="lbl-long">{mode === 'user' ? (role === 'owner' ? 'Go to server admin' : 'Go to reseller panel') : 'Go to my hosting'}</span><span className="lbl-short">{mode === 'user' ? (role === 'owner' ? 'Admin' : 'Reseller') : 'Hosting'}</span></button>}
            <button className="icon-btn" aria-label="Find a tool" title="Find a tool" onClick={() => { setOpen(true); setTimeout(() => { const i = document.querySelector('.sec-nav .find-tool input'); if (i) i.focus() }, 60) }}><Icon name="search" /></button>
            <button className="icon-btn" aria-label={dark ? 'Light look' : 'Dark look'} title={dark ? 'Light look' : 'Dark look'} onClick={flipTheme}><Icon name={dark ? 'sun' : 'moon'} /></button>
            <div className="user-menu">
              <button className="user-btn" aria-expanded={userMenu} onClick={() => setUserMenu(!userMenu)}><span className="avatar">{(me.user.name || '?').slice(0, 1).toUpperCase()}</span><span className="user-name">{me.user.name}<small>{role}</small></span></button>
              {userMenu && (
                <div className="dropdown" role="menu">
                  <button role="menuitem" onClick={() => go('security')}><Icon name="user" size={16} /> My account</button>
                  <button role="menuitem" onClick={() => go('tickets')}><Icon name="ticket" size={16} /> Support tickets</button>
                  <LanguagePick onPick={(c) => api('/me/language', { language: c }).catch(() => {})} />
                  <button role="menuitem" onClick={logout}><Icon name="logout" size={16} /> Sign out</button>
                </div>
              )}
            </div>
          </div>
        </header>
        {me.signed_in_as_by && <div className="imp-bar" role="status"><span>You are signed in as <b>{me.user.name}</b> (from {me.signed_in_as_by}).</span><button className="btn sm" onClick={() => api('/login-back', {}).then(() => { window.location.hash = 'users'; window.location.reload() }).catch(() => setMe(null))}>Back to my account</button></div>}
        {me.user.demo && <div className="imp-bar demo-bar" role="status"><span><b>{'Demo'}</b> {'- you can look at everything; nothing is changed on this server.'}</span></div>}
        <DialogHost />
        <main className="main" id="main">{cur === 'tool' ? <SiteTool key={tab} feature={toolFeature} /> : <View key={cur + '/' + (tab || '')} me={me} reload={load} go={go} tab={tab} mode={mode} />}</main>
      </div>
    </div>
  )
}
