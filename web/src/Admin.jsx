import { ask, show } from './dialog.jsx'
import React, { useEffect, useState } from 'react'
import { api } from './api.js'

export function Firewall() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [rule, setRule] = useState({ port: '', proto: 'tcp' })
  const [ip, setIp] = useState('')
  const [ssh, setSsh] = useState({ port: '', root_login: true })
  const apply = (p) => { setErr(''); return p.then((r) => { setD(r); setSsh({ port: r.ssh_port, root_login: r.root_login }) }).catch((x) => setErr(x.message)) }
  useEffect(() => { apply(api('/firewall')) }, [])
  if (!d) return <p className="muted">{err || 'Loading...'}</p>
  return (
    <>
      <h1>Firewall and SSH</h1>{err && <div className="alert bad" role="alert">{err}</div>}
      <div className="card"><div className="row sp"><div><strong>Firewall is {d.active ? 'on' : 'off'}</strong><div className="muted small">Panel port {d.panel_port}, SSH port {d.ssh_port}. These two are protected from being blocked.</div></div>
        <button className="btn" onClick={() => apply(api('/firewall/enable', { on: !d.active }))}>{d.active ? 'Switch off' : 'Switch on'}</button></div>
        <div className="muted small" style={{ marginTop: 6 }}>Attack protection (fail2ban): {d.fail2ban.running ? 'running, ' + d.fail2ban.banned + ' addresses blocked now' : 'not running'}</div></div>
      <div className="card"><h2>Open or close a port</h2><div className="row">
        <label>Port<input type="number" min="1" max="65535" value={rule.port} onChange={(e) => setRule({ ...rule, port: e.target.value })} /></label>
        <label>Protocol<select value={rule.proto} onChange={(e) => setRule({ ...rule, proto: e.target.value })}><option>tcp</option><option>udp</option></select></label>
        <button className="btn primary" style={{ alignSelf: 'flex-end' }} onClick={() => apply(api('/firewall/allow', { port: +rule.port, proto: rule.proto }))}>Open</button>
        <button className="btn danger" style={{ alignSelf: 'flex-end' }} onClick={() => apply(api('/firewall/deny', { port: +rule.port, proto: rule.proto }))}>Block</button></div></div>
      <div className="card"><h2>Block an address</h2><div className="row"><label style={{ minWidth: 240 }}>IP address<input value={ip} onChange={(e) => setIp(e.target.value)} placeholder="203.0.113.7 or 203.0.113.0/24" /></label>
        <button className="btn danger" style={{ alignSelf: 'flex-end' }} onClick={() => apply(api('/firewall/block-ip', { ip })).then(() => setIp(''))}>Block</button></div></div>
      <div className="card"><h2>SSH</h2><div className="form"><label>SSH port<input type="number" value={ssh.port} onChange={(e) => setSsh({ ...ssh, port: e.target.value })} /></label>
        <label style={{ display: 'flex', flexDirection: 'row', gap: 8, alignItems: 'center' }}><input type="checkbox" style={{ width: 'auto' }} checked={ssh.root_login} onChange={(e) => setSsh({ ...ssh, root_login: e.target.checked })} /> Allow root to sign in with a password</label></div>
        <p className="muted small">The new port is opened in the firewall first, and the settings are tested before they are applied. Keep your current session open until you have tried the new one.</p>
        <button className="btn primary" onClick={() => window.confirm('Apply the SSH settings?') && apply(api('/firewall/ssh', { port: +ssh.port, root_login: ssh.root_login }))}>Apply</button></div>
      <div className="card wrap"><h2>Rules</h2><table className="table"><thead><tr><th>#</th><th>To</th><th>Action</th><th>From</th><th /></tr></thead><tbody>
        {d.rules.length === 0 && <tr><td colSpan="5" className="muted">No rules, or the firewall is off.</td></tr>}
        {d.rules.map((r) => <tr key={r.number}><td>{r.number}</td><td>{r.to}</td><td>{r.action}</td><td>{r.from}</td><td><button className="btn sm danger" onClick={() => window.confirm('Delete rule ' + r.number + '?') && apply(api('/firewall/rules/' + r.number + '/delete', {}))}>Delete</button></td></tr>)}
      </tbody></table></div>
    </>
  )
}

export function Users({ me }) {
  const [list, setList] = useState(null)
  const [err, setErr] = useState('')
  const [f, setF] = useState({ name: '', password: '', role: 'client', email: '', domain: '', plan_id: '', sites: '', databases: '' })
  const [plans, setPlans] = useState([])
  const [sys, setSys] = useState(null)
  const [msg, setMsg] = useState('')
  const owner = me.user.role === 'owner'
  const load = () => api('/users').then((r) => setList(r.users)).catch((e) => setErr(e.message))
  useEffect(() => {
    load()
    api('/plans').then((r) => setPlans(r.plans || [])).catch(() => {})
    if (owner) api('/server-users').then((r) => setSys(r.users)).catch(() => {})
  }, [])
  const save = (e) => {
    e.preventDefault(); setErr(''); setMsg('')
    api('/users', { name: f.name, password: f.password, role: f.role, email: f.email, domain: f.domain, plan_id: f.plan_id || null, quota: { sites: f.sites, databases: f.databases } })
      .then((r) => { setMsg('Account ' + r.user.name + ' made' + (r.site ? ' with the website ' + r.site.domain : '') + '.'); setF({ ...f, name: '', password: '', email: '', domain: '' }); load() }).catch((x) => setErr(x.message))
  }
  const adopt = async (s) => {
    const pw = await ask('Panel password for ' + s.name + ' (10+ characters, letters and digits). Its Linux login does not change.')
    if (!pw) return
    const role = window.confirm('Make ' + s.name + ' a reseller (can add own clients)? OK = reseller, Cancel = client') ? 'reseller' : 'client'
    setErr(''); setMsg('')
    api('/server-users/adopt', { name: s.name, password: pw, role }).then((r) => { setSys(r.users); setMsg(s.name + ' is now a panel account (' + role + ').'); load() }).catch((x) => setErr(x.message))
  }
  // limits like WHM "Modify an account": every value at once (saving keeps the feature switches and the values not shown)
  const [lim, setLim] = useState(null)
  const LIMITS = [['sites', 'Websites'], ['databases', 'Databases'], ['crons', 'Cron jobs'], ['backups', 'Backups'], ['disk_mb', 'Disk (MB)'], ['bandwidth_gb', 'Bandwidth per month (GB)'], ['mail_per_hour', 'Mails per hour'], ['inodes', 'Files and folders (inodes)']]
  const quota = (u) => setLim({ u, q: { ...(u.quota || {}) } })
  const saveLim = (e) => { e.preventDefault(); api('/users/' + lim.u.id, { quota: lim.q }).then(() => { setLim(null); setMsg('Limits saved.'); load() }).catch((x) => setErr(x.message)) }
  return (
    <>
      <h1>Accounts</h1>{err && <div className="alert bad" role="alert">{err}</div>}{msg && <div className="alert ok" role="status">{msg}</div>}
      <form className="card" onSubmit={save}><h2>Create an account</h2><p className="muted small" style={{ marginTop: 0 }}>Like WHM "Create a New Account": the login, its hosting plan and its main website in one step. Each account gets its own Linux user on the server.</p><div className="form">
        <label>User name<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></label>
        <label>Password (10+ characters, letters and digits)<input type="password" autoComplete="new-password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} required /></label>
        <label>E-mail<input type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></label>
        <label>Main domain (optional)<input value={f.domain} onChange={(e) => setF({ ...f, domain: e.target.value })} placeholder="example.com" /></label>
        <label>Hosting plan<select value={f.plan_id} onChange={(e) => setF({ ...f, plan_id: e.target.value })}><option value="">No plan (limits below)</option>{plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
        {me.user.role === 'owner' && <label>Role<select value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}><option value="client">Client</option><option value="reseller">Reseller (can add own clients)</option><option value="owner">Owner (everything)</option></select></label>}
        <label>Websites allowed<input type="number" min="0" value={f.sites} onChange={(e) => setF({ ...f, sites: e.target.value })} placeholder="no limit" /></label>
        <label>Databases allowed<input type="number" min="0" value={f.databases} onChange={(e) => setF({ ...f, databases: e.target.value })} placeholder="no limit" /></label></div>
        <div style={{ marginTop: 12 }}><button className="btn primary">Create account</button></div></form>
      {owner && sys && (
        <div className="card"><h2>Users already on this server</h2>
          <p className="muted small" style={{ marginTop: 0 }}>The people's Linux users of this computer (like the one you sign in to it with). Make one a panel account: its websites then run as that user, and its Linux login stays as it is.</p>
          {sys.length === 0 ? <p className="muted">No other users on this server.</p> : (
            <div className="wrap"><table className="table"><thead><tr><th>Linux user</th><th>Home folder</th><th>Panel account</th><th /></tr></thead><tbody>
              {sys.map((s) => <tr key={s.name}><td><strong>{s.name}</strong> <span className="small muted">uid {s.uid}</span></td><td className="small">{s.home}</td><td>{s.account || <span className="muted">not yet</span>}</td>
                <td>{!s.account && <button className="btn sm" onClick={() => adopt(s)}>Make it an account</button>}</td></tr>)}
            </tbody></table></div>)}
        </div>)}
      {!list ? <p className="muted">Loading...</p> : (
        <div className="card wrap"><table className="table"><thead><tr><th>Name</th><th>Role</th><th>Uses</th><th>Status</th><th /></tr></thead><tbody>
          {list.map((u) => <tr key={u.id}><td><strong>{u.name}</strong>{u.two_step && <span className="pill" style={{ marginLeft: 6 }}>2-step</span>}</td><td>{u.role}</td><td className="small">{Object.entries(u.usage).map(([k, v]) => k + ' ' + v + (u.quota[k] != null ? '/' + u.quota[k] : '')).join(', ')}</td>
            <td><span className={'pill ' + (u.active ? 'on' : 'off')}>{u.active ? 'active' : 'blocked'}</span></td>
            <td className="row">{u.id !== me.user.id && <><button className="btn sm" onClick={() => quota(u)}>Limits</button>
              <button className="btn sm" onClick={async () => { const p = await ask('New password for ' + u.name); if (p) api('/users/' + u.id, { password: p }).then(() => alert('Password changed.')).catch((x) => setErr(x.message)) }}>Password</button>
              <button className="btn sm" onClick={() => api('/users/' + u.id, { active: !u.active }).then(load)}>{u.active ? 'Block' : 'Unblock'}</button>
              {u.role !== 'owner' && <button className="btn sm danger" onClick={async () => { const c = await ask('Delete ' + u.name + ' with ALL websites, files, databases, mail, DNS and backups? Type the user name to confirm.'); if (c) api('/users/' + u.id + '/terminate', { confirm: c }).then(load).catch((x) => setErr(x.message)) }}>Delete</button>}
              {u.role !== 'owner' && <button className="btn sm" onClick={() => window.confirm('Sign in as ' + u.name + '? You come back with the button at the top.') && api('/users/' + u.id + '/login-as', {}).then(() => { window.location.hash = 'home'; window.location.reload() }).catch((x) => setErr(x.message))}>Sign in as</button>}
              {u.role !== 'owner' && <button className="btn sm" title="A link that opens this account's panel already signed in - one use, 60 seconds" onClick={() => api('/billing/accounts/' + encodeURIComponent(u.name) + '/sso', {}).then((r) => { const link = window.location.origin + r.url; try { navigator.clipboard.writeText(link) } catch (e) { /* no clipboard */ } show('Auto-login link for ' + u.name + ' (copied; works once, for 60 seconds):', link) }).catch((x) => setErr(x.message))}>Login link</button>}</>}</td></tr>)}
        </tbody></table></div>
      )}
          {lim && (
        <div className="modal" role="dialog" aria-modal="true" aria-label="Limits"><form className="card" style={{ width: 'min(560px,100%)' }} onSubmit={saveLim}>
          <h2>Limits of {lim.u.name}</h2>
          <p className="muted small" style={{ marginTop: 0 }}>Empty = no limit.{me.user.role === 'reseller' ? ' You can give at most your own limits.' : ''}</p>
          <div className="grid2">{[...LIMITS, ...(lim.u.role === 'reseller' ? [['accounts', 'Accounts it may create']] : [])].map(([k, l]) => (
            <label key={k} className="field"><span>{l}</span><input type="number" min="0" value={lim.q[k] ?? ''} placeholder="no limit" onChange={(e) => { const q = { ...lim.q }; if (e.target.value === '') delete q[k]; else q[k] = e.target.value; setLim({ ...lim, q }) }} /></label>
          ))}</div>
          <div className="row" style={{ gap: 8 }}><button className="btn primary">Save</button><button type="button" className="btn" onClick={() => setLim(null)}>Cancel</button></div>
        </form></div>
      )}
</>
  )
}

export function Security({ me, reload }) {
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [pw, setPw] = useState({ current: '', password: '' })
  const [two, setTwo] = useState(null)
  const [code, setCode] = useState('')
  const [tokens, setTokens] = useState([])
  const [tname, setTname] = useState('')
  const [fresh, setFresh] = useState('')
  const load = () => api('/account/tokens').then((r) => setTokens(r.tokens))
  useEffect(() => { load() }, [])
  const wrap = (p, m) => { setErr(''); setMsg(''); return p.then((r) => { setMsg(m); return r }).catch((x) => setErr(x.message)) }
  return (
    <>
      <h1>My account</h1>{err && <div className="alert bad" role="alert">{err}</div>}{msg && <div className="alert good" role="status">{msg}</div>}
      <form className="card" onSubmit={(e) => { e.preventDefault(); wrap(api('/account/password', pw), 'Password changed. Please sign in again.').then(() => setTimeout(() => window.location.reload(), 1200)) }}><h2>Change password</h2><div className="form">
        <label>Current password<input type="password" autoComplete="current-password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} required /></label>
        <label>New password<input type="password" autoComplete="new-password" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} required /></label></div><div style={{ marginTop: 12 }}><button className="btn primary">Change</button></div></form>
      <div className="card"><h2>Two-step sign-in</h2>
        {me.user.two_step ? <><p className="ok">Two-step sign-in is on.</p><button className="btn danger" onClick={async () => { const p = await ask('Enter your password to switch it off'); if (p) wrap(api('/account/two-step/off', { password: p }), 'Two-step sign-in is off.').then(reload) }}>Switch off</button></>
          : two ? <><p>Add this key to an authenticator app (Google Authenticator, Aegis, Authy...):</p><p className="code" style={{ fontSize: 16, padding: 8 }}>{two.secret}</p><p className="muted small">Or open on your phone: <span className="code">{two.uri}</span></p>
            <div className="row"><label>6-digit code<input inputMode="numeric" value={code} onChange={(e) => setCode(e.target.value)} /></label><button className="btn primary" style={{ alignSelf: 'flex-end' }} onClick={() => wrap(api('/account/two-step/confirm', { code }), 'Two-step sign-in is on.').then(() => { setTwo(null); reload() })}>Confirm</button></div></>
            : <button className="btn primary" onClick={() => api('/account/two-step/start', {}).then(setTwo)}>Set up two-step sign-in</button>}</div>
      <div className="card"><h2>API tokens</h2><p className="muted small">For scripts and deployments. A token can do what you can do. Send it as <span className="code">Authorization: Bearer ...</span>.</p>
        <form className="row" onSubmit={(e) => { e.preventDefault(); api('/account/tokens', { name: tname }).then((r) => { setFresh(r.token); setTname(''); load() }).catch((x) => setErr(x.message)) }}><label style={{ minWidth: 220 }}>Name<input value={tname} onChange={(e) => setTname(e.target.value)} placeholder="deploy script" required /></label><button className="btn primary" style={{ alignSelf: 'flex-end' }}>Create token</button></form>
        {fresh && <div className="alert good"><p>Copy it now, it is not shown again:</p><p className="code">{fresh}</p></div>}
        {tokens.map((t) => <div key={t.id} className="row sp" style={{ padding: '6px 0', borderTop: '1px solid var(--border)' }}><span>{t.name} <span className="muted small">last used {t.last_used ? new Date(t.last_used * 1000).toLocaleString() : 'never'}</span></span><button className="btn sm danger" onClick={() => api('/account/tokens/' + t.id + '/delete', {}).then(load)}>Delete</button></div>)}</div>
    </>
  )
}

export function Settings({ me, tab: start }) {
  const [tab, setTab] = useState(start || 'brand')
  return (
    <>
      <h1>Settings</h1>
      <div className="tabs" role="tablist">{[['brand', 'Brand'], ['backups', 'Backups'], ['alerts', 'Alerts'], ['updates', 'Updates'], ...(me.edition === 'community' ? [] : [['licence', 'Licence']]), ['audit', 'Activity log']].map(([k, l]) => <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}>{l}</button>)}</div>
      {tab === 'brand' && <Brand me={me} />}{tab === 'backups' && <BackupSettings />}{tab === 'alerts' && <AlertSettings />}{tab === 'updates' && <UpdateBox />}{tab === 'licence' && <LicenceBox />}{tab === 'audit' && <AuditLog />}
    </>
  )
}

// a reseller's own brand (WHM "Branding"): their clients see it instead of the server owner's
export function ResellerBrand({ me }) {
  return <><h1>Your brand</h1><Brand me={me} /></>
}

function Brand({ me }) {
  const [f, setF] = useState({ name: me.brand.name, color: me.brand.color, logo: me.brand.logo || '', support: me.brand.support || '' })
  const save = () => me.user.role === 'owner' ? api('/settings/brand', f) : api('/users/' + me.user.id, { brand: f })
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  return (
    <form className="card" onSubmit={(e) => { e.preventDefault(); setErr(''); save().then(() => { setMsg('Saved. Reload the page to see it everywhere.') }).catch((x) => setErr(x.message)) }}>
      <h2>Name and look</h2><p className="muted small">{me.user.role === 'owner' ? 'Everybody sees this name, colour and logo. A reseller can set their own (Accounts > Your brand) for their clients.' : 'Your clients see this name, colour and logo instead of the server owner\'s.'}</p>
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert good">{msg}</div>}
      <div className="form"><label>Panel name<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></label><label>Colour<input type="color" value={f.color} onChange={(e) => setF({ ...f, color: e.target.value })} style={{ height: 42 }} /></label>
        <label>Logo address (https)<input value={f.logo} onChange={(e) => setF({ ...f, logo: e.target.value })} /></label><label>Support e-mail<input type="email" value={f.support} onChange={(e) => setF({ ...f, support: e.target.value })} /></label></div>
      <div style={{ marginTop: 12 }}><button className="btn primary">Save</button></div></form>
  )
}

function BackupSettings() {
  const [f, setF] = useState({ keep: 7, endpoint: '', bucket: '', region: '', access: '', secret: '', prefix: '' })
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  useEffect(() => { api('/backups').then((r) => setF((x) => ({ ...x, keep: r.keep }))) }, [])
  return (
    <form className="card" onSubmit={(e) => { e.preventDefault(); setErr(''); setMsg(''); const { keep, ...cloud } = f; api('/settings/backups', { keep, cloud }).then((r) => { setMsg(r.cloud ? 'Saved. Cloud storage is on.' : 'Saved.'); setF((x) => ({ ...x, secret: '' })) }).catch((x) => setErr(x.message)) }}>
      <h2>Backups</h2>{err && <div className="alert bad">{err}</div>}{msg && <div className="alert good">{msg}</div>}
      <label style={{ maxWidth: 220 }}>Keep this many backups of each item<input type="number" min="1" max="60" value={f.keep} onChange={(e) => setF({ ...f, keep: e.target.value })} /></label>
      <h3>Cloud storage (S3 compatible)</h3><p className="muted small">AWS S3, Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO... Leave the keys empty to keep the saved ones.</p>
      <div className="form"><label>Endpoint<input value={f.endpoint} onChange={(e) => setF({ ...f, endpoint: e.target.value })} placeholder="s3.amazonaws.com" /></label><label>Bucket<input value={f.bucket} onChange={(e) => setF({ ...f, bucket: e.target.value })} /></label>
        <label>Region<input value={f.region} onChange={(e) => setF({ ...f, region: e.target.value })} placeholder="us-east-1" /></label><label>Folder in the bucket (optional)<input value={f.prefix} onChange={(e) => setF({ ...f, prefix: e.target.value })} /></label>
        <label>Access key<input autoComplete="off" value={f.access} onChange={(e) => setF({ ...f, access: e.target.value })} /></label><label>Secret key<input type="password" autoComplete="off" value={f.secret} onChange={(e) => setF({ ...f, secret: e.target.value })} /></label></div>
      <div style={{ marginTop: 12 }}><button className="btn primary">Save</button></div></form>
  )
}

function AlertSettings() {
  const [d, setD] = useState(null)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  useEffect(() => { api('/alerts').then(setD) }, [])
  if (!d) return <p className="muted">Loading...</p>
  const s = d.settings
  return (
    <>
      <form className="card" onSubmit={(e) => { e.preventDefault(); setErr(''); api('/alerts', s).then(() => setMsg('Saved.')).catch((x) => setErr(x.message)) }}>
        <h2>Alerts</h2><p className="muted small">Checked every 5 minutes: disk, memory, and whether nginx and the database are running. The same problem is sent once an hour.</p>
        {err && <div className="alert bad">{err}</div>}{msg && <div className="alert good">{msg}</div>}
        <div className="form"><label>Send e-mail to<input type="email" value={s.email} onChange={(e) => setD({ ...d, settings: { ...s, email: e.target.value } })} /></label><label>Webhook (Slack, Discord, any https address)<input value={s.webhook} onChange={(e) => setD({ ...d, settings: { ...s, webhook: e.target.value } })} /></label>
          <label>Disk alert at (%)<input type="number" min="50" max="99" value={s.disk} onChange={(e) => setD({ ...d, settings: { ...s, disk: e.target.value } })} /></label><label>Memory alert at (%)<input type="number" min="50" max="99" value={s.memory} onChange={(e) => setD({ ...d, settings: { ...s, memory: e.target.value } })} /></label>
          <label>Telegram bot token (from @BotFather)<input type="password" autoComplete="new-password" value={s.telegram_token || ''} onChange={(e) => setD({ ...d, settings: { ...s, telegram_token: e.target.value } })} placeholder={s.telegram_saved ? 'Saved - leave blank to keep' : '123456:ABC...'} /></label>
          <label>Telegram chat id<input value={s.telegram_chat || ''} onChange={(e) => setD({ ...d, settings: { ...s, telegram_chat: e.target.value } })} placeholder="-1001234567890 or @yourchannel" /></label></div>
        <div style={{ marginTop: 12 }}><button className="btn primary">Save</button></div></form>
      <div className="card"><h2>Recent alerts</h2>{d.recent.length === 0 ? <p className="muted">Nothing so far.</p> : d.recent.map((a) => <div key={a.id} className="row sp" style={{ padding: '6px 0', borderTop: '1px solid var(--border)' }}><span>{a.message}</span><span className="muted small">{a.created_at.slice(0, 16).replace('T', ' ')}</span></div>)}</div>
    </>
  )
}

function LicenceBox() {
  const [l, setL] = useState(null)
  const [key, setKey] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { api('/licence').then(setL).catch((e) => setErr(e.message)) }, [])
  const send = (path, body) => { setBusy(true); setErr(''); api(path, body).then((r) => { setL({ ...l, ...r }); setKey('') }).catch((x) => setErr(x.message)).finally(() => setBusy(false)) }
  return (
    <div className="card"><h2>Licence</h2>{err && <div className="alert bad">{err}</div>}
      {l && <>
        <p className={l.state === 'expired' ? 'alert bad' : l.state === 'active' ? 'alert good' : 'alert'}>{l.message}</p>
        {l.key && <p className="muted small">Key {l.key}{l.checked_at ? ' - checked ' + new Date(l.checked_at * 1000).toLocaleString() : ''}</p>}
        <form className="row" style={{ gap: 8, flexWrap: 'wrap' }} onSubmit={(e) => { e.preventDefault(); send('/licence', { key }) }}>
          <input aria-label="Licence key" placeholder="ZP-XXXX-XXXX-XXXX-XXXX" value={key} onChange={(e) => setKey(e.target.value)} required style={{ flex: '1 1 240px', maxWidth: 360, fontFamily: 'monospace' }} />
          <button className="btn primary" disabled={busy}>{busy ? 'Checking...' : 'Save key'}</button>
          {l.key && <button type="button" className="btn" disabled={busy} onClick={() => send('/licence/refresh', {})}>Check again</button>}
        </form>
        <p className="muted small">One licence per server. Without a valid licence your websites, mail and databases keep running; only changes from the panel stop. Prices: <a href="https://panel.zarsx.in/#prices" target="_blank" rel="noopener noreferrer">panel.zarsx.in</a>. The panel checks the key every night.</p>
      </>}</div>
  )
}

function UpdateBox() {
  const [c, setC] = useState(null)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { api('/updates').then(setC).catch((e) => setErr(e.message)) }, [])
  return (
    <div className="card"><h2>Panel updates</h2>{err && <div className="alert bad">{err}</div>}{msg && <div className="alert good">{msg}</div>}
      {c && <><p>You have version <strong>{c.current}</strong>. Newest: <strong>{c.latest}</strong>.</p>{c.notes && <p className="muted">{c.notes}</p>}
        <label className="row" style={{ gap: 8, alignItems: 'center' }}><input type="checkbox" checked={!!c.auto} onChange={(e) => api('/updates/auto', { on: e.target.checked }).then(() => window.location.reload()).catch((x) => setErr(x.message))} /> Install new versions by themselves (checked every night{c.checked_at ? ', last ' + String(c.checked_at).slice(0, 16).replace('T', ' ') : ''})</label>
        {c.update ? <button className="btn primary" disabled={busy} onClick={() => { setBusy(true); api('/updates/apply', {}).then((r) => setMsg('Version ' + r.installed + ' installed. Reload the page.')).catch((x) => setErr(x.message)).finally(() => setBusy(false)) }}>{busy ? 'Updating...' : 'Update now'}</button> : <p className="ok">You are up to date.</p>}
        <p className="muted small">Updates are only installed when their checksum and ZARSCO's signature are valid.</p></>}</div>
  )
}

function AuditLog() {
  const [rows, setRows] = useState(null)
  useEffect(() => { api('/audit').then((r) => setRows(r.audit)) }, [])
  return (
    <div className="card wrap"><div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, justifyContent: 'space-between', alignItems: 'center' }}><h2>Activity log</h2><a className="btn" href="/api/audit.csv">Download CSV</a></div>{!rows ? <p className="muted">Loading...</p> : <table className="table"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead><tbody>
      {rows.map((r) => <tr key={r.id}><td className="small">{r.created_at.slice(0, 19).replace('T', ' ')}</td><td>{r.user || '-'}</td><td>{r.action}</td><td className="small muted">{r.detail}</td></tr>)}</tbody></table>}</div>
  )
}
