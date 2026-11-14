import { ask, show as showValue } from './dialog.jsx'
import React, { useEffect, useRef, useState } from 'react'
import { api, bytes } from './api.js'

// Features of the other control panels (0.11.0): Plesk, DirectAdmin, CyberPanel, HestiaCP, aaPanel, CloudPanel, ISPConfig, Virtualmin.
// Server tools tabs (owner), the website "Tools" tab, and the pages Uptime, Move from another server and Cloudflare.

function useGet(path) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const load = () => (path ? api(path).then((x) => { setD(x); setErr('') }).catch((e) => setErr(e.message)) : null)
  useEffect(() => { load() }, [path])
  return [d, load, err, setErr, setD]
}

function useRun(setErr) {
  const [msg, setMsg] = useState('')
  const run = (p, m, after) => p.then((r) => { setMsg(m || 'Saved.'); setErr(''); if (after) after(r); return r }).catch((e) => { setMsg(''); setErr(e.message) })
  return [msg, run]
}

function Box({ err, msg, children }) {
  return <div className="card">{err && <div className="alert bad" role="alert">{err}</div>}{msg && <div className="alert" role="status">{msg}</div>}{children}</div>
}

const when = (t) => (t ? new Date(t * 1000).toLocaleString() : '-')

export const PLUS_TABS = [['advisor', 'Advisor'], ['history', 'History graphs'], ['services', 'System services'], ['journal', 'System logs'], ['time', 'Time and swap'], ['jails', 'Fail2ban'], ['countries', 'Block countries'], ['paccess', 'Panel access'], ['rclone', 'Remote backup storage'], ['cluster', 'DNS cluster'], ['imunify', 'Malware scanner (Imunify)'], ['plimits', 'Passwords and processes'], ['siteusers', 'Account users']]

export function PlusTab({ tab }) {
  return <>{tab === 'advisor' && <Advisor />}{tab === 'history' && <History />}{tab === 'services' && <Services />}{tab === 'journal' && <Journal />}{tab === 'time' && <TimeSwap />}
    {tab === 'jails' && <Jails />}{tab === 'countries' && <Countries />}{tab === 'paccess' && <Access />}{tab === 'rclone' && <Rclone />}{tab === 'cluster' && <Cluster />}{tab === 'imunify' && <ImunifyTab />}{tab === 'plimits' && <Limits />}{tab === 'siteusers' && <SiteUsers />}</>
}

// ---- advisor ---------------------------------------------------------------------------------------------------------------
function Advisor() {
  const [d, , err] = useGet('/plus/advisor')
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <Box err={err}>
      <h3>Security and health score: {d.score} / 100</h3>
      <div className="meter" role="img" aria-label={'Score ' + d.score + ' percent'}><i style={{ width: d.score + '%' }} /></div>
      <ul className="checklist">
        {d.checks.map((c) => <li key={c.key} className={c.ok ? 'ok' : 'todo'}><span aria-hidden="true">{c.ok ? '✓' : '!'}</span><span className="grow">{c.text}</span>
          {!c.ok && <a className="btn sm" href={'#' + c.to}>Fix</a>}</li>)}
      </ul>
    </Box>
  )
}

// ---- history graphs: one measure per chart, hover shows the value, table view for every chart -------------------------
function LineChart({ title, points, field, unit, max, fmt }) {
  const [hover, setHover] = useState(null)
  const [W, setW] = useState(600)
  const ref = useRef(null)
  useEffect(() => { // draw at the real width so the labels keep their size on a phone
    if (!ref.current || !window.ResizeObserver) return
    const o = new ResizeObserver(([e]) => setW(Math.max(260, Math.round(e.contentRect.width))))
    o.observe(ref.current.parentNode)
    return () => o.disconnect()
  }, [])
  const H = 160, P = { l: 52, r: 10, t: 10, b: 22 }
  if (!points.length) return <div className="chart"><h4>{title}</h4><p className="muted small">No numbers yet. The first point is written within 5 minutes.</p></div>
  const t0 = points[0].t, t1 = points[points.length - 1].t || t0 + 1
  const top = max || Math.max(1, ...points.map((p) => p[field])) * 1.15
  const x = (t) => P.l + ((t - t0) / Math.max(1, t1 - t0)) * (W - P.l - P.r)
  const y = (v) => H - P.b - (v / top) * (H - P.t - P.b)
  const d = points.map((p, i) => (i ? 'L' : 'M') + x(p.t).toFixed(1) + ' ' + y(p[field]).toFixed(1)).join(' ')
  const show = fmt || ((v) => v + unit)
  const move = (e) => {
    const r = ref.current.getBoundingClientRect()
    const tx = t0 + ((e.clientX - r.left) / r.width * W - P.l) / (W - P.l - P.r) * (t1 - t0)
    let best = points[0]
    for (const p of points) if (Math.abs(p.t - tx) < Math.abs(best.t - tx)) best = p
    setHover(best)
  }
  return (
    <div className="chart">
      <h4>{title}{hover && <span className="muted small"> {new Date(hover.t * 1000).toLocaleString()}: <b>{show(hover[field])}</b></span>}</h4>
      <svg ref={ref} viewBox={`0 0 ${W} ${H}`} width={W} height={H} role="img" aria-label={title} onMouseMove={move} onMouseLeave={() => setHover(null)}>
        {[0, 0.5, 1].map((f) => <g key={f}><line x1={P.l} x2={W - P.r} y1={y(top * f)} y2={y(top * f)} className="grid" /><text x={P.l - 6} y={y(top * f) + 4} className="tick" textAnchor="end">{show(Math.round(top * f))}</text></g>)}
        <text x={P.l} y={H - 6} className="tick">{new Date(t0 * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</text>
        <text x={W - P.r} y={H - 6} className="tick" textAnchor="end" visibility={W < 330 ? 'hidden' : 'visible'}>{new Date(t1 * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</text>
        <path d={d} className="line" />
        {hover && <><line x1={x(hover.t)} x2={x(hover.t)} y1={P.t} y2={H - P.b} className="cross" /><circle cx={x(hover.t)} cy={y(hover[field])} r="4" className="dot" /></>}
      </svg>
    </div>
  )
}

function History() {
  const [range, setRange] = useState('24h')
  const [table, setTable] = useState(false)
  const [d, , err] = useGet('/plus/history?range=' + range)
  const pts = d ? d.points : []
  const rate = (v) => bytes(v) + '/s'
  return (
    <Box err={err}>
      <div className="row" style={{ marginBottom: 10 }}>
        {[['24h', 'Last 24 hours'], ['7d', 'Last 7 days'], ['30d', 'Last 30 days']].map(([k, l]) => <button key={k} className={'btn sm' + (range === k ? ' primary' : '')} aria-pressed={range === k} onClick={() => setRange(k)}>{l}</button>)}
        <span style={{ flex: 1 }} /><button className="btn sm" onClick={() => setTable(!table)}>{table ? 'Show graphs' : 'Show as a table'}</button>
      </div>
      {!d ? <p className="muted">Loading...</p> : table ? (
        <div className="wrap"><table className="table"><thead><tr><th>When</th><th>CPU %</th><th>Memory %</th><th>Disk %</th><th>Load</th><th>In</th><th>Out</th></tr></thead><tbody>
          {pts.slice().reverse().map((p) => <tr key={p.t}><td>{when(p.t)}</td><td>{p.cpu}</td><td>{p.mem}</td><td>{p.disk}</td><td>{p.load}</td><td>{rate(p.rx)}</td><td>{rate(p.tx)}</td></tr>)}
        </tbody></table></div>
      ) : (
        <div className="charts">
          <LineChart title="CPU" points={pts} field="cpu" unit="%" max={100} />
          <LineChart title="Memory" points={pts} field="mem" unit="%" max={100} />
          <LineChart title="Disk" points={pts} field="disk" unit="%" max={100} />
          <LineChart title="Load" points={pts} field="load" unit="" />
          <LineChart title="Network in" points={pts} field="rx" unit="" fmt={rate} />
          <LineChart title="Network out" points={pts} field="tx" unit="" fmt={rate} />
        </div>
      )}
    </Box>
  )
}

// ---- system services and logs ------------------------------------------------------------------------------------------------
function Services() {
  const [d, , err, setErr, setD] = useGet('/plus/services')
  const [msg, run] = useRun(setErr)
  const [q, setQ] = useState('')
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const act = (n, a) => run(api('/plus/services/' + encodeURIComponent(n) + '/' + a, {}), n + ': ' + a + ' done.', setD)
  const list = d.services.filter((s) => !q || (s.name + s.about).toLowerCase().includes(q.toLowerCase()))
  return (
    <Box err={err} msg={msg}>
      <label>Find<input value={q} onChange={(e) => setQ(e.target.value)} placeholder="nginx, mariadb, php ..." /></label>
      <div className="wrap"><table className="table"><thead><tr><th>Service</th><th>State</th><th>At start-up</th><th /></tr></thead><tbody>
        {list.map((s) => <tr key={s.name}><td><b>{s.name}</b><div className="muted small">{s.about}</div></td><td><span className={'pill ' + (s.active === 'active' ? 'on' : 'off')}>{s.sub}</span></td><td>{s.boot || '-'}</td>
          <td className="row">{s.active === 'active' ? <><button className="btn sm" onClick={() => act(s.name, 'restart')}>Restart</button>{!s.locked && <button className="btn sm" onClick={() => window.confirm('Stop ' + s.name + '?') && act(s.name, 'stop')}>Stop</button>}</> : <button className="btn sm" onClick={() => act(s.name, 'start')}>Start</button>}
            {s.boot === 'enabled' ? !s.locked && <button className="btn sm" onClick={() => act(s.name, 'disable')}>Not at start-up</button> : s.boot === 'disabled' && <button className="btn sm" onClick={() => act(s.name, 'enable')}>At start-up</button>}</td></tr>)}
      </tbody></table></div>
    </Box>
  )
}

function Journal() {
  const [f, setF] = useState({ unit: '', lines: 200, find: '' })
  const [path, setPath] = useState('/plus/journal?lines=200')
  const [d, load, err] = useGet(path)
  return (
    <Box err={err}>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); setPath('/plus/journal?unit=' + encodeURIComponent(f.unit) + '&lines=' + f.lines + '&find=' + encodeURIComponent(f.find)) }}>
        <label style={{ minWidth: 200 }}>Service (empty: all)<input value={f.unit} onChange={(e) => setF({ ...f, unit: e.target.value })} placeholder="nginx.service" /></label>
        <label style={{ width: 110 }}>Lines<input type="number" min="20" max="2000" value={f.lines} onChange={(e) => setF({ ...f, lines: e.target.value })} /></label>
        <label style={{ minWidth: 180 }}>Containing<input value={f.find} onChange={(e) => setF({ ...f, find: e.target.value })} placeholder="error" /></label>
        <button className="btn primary">Show</button><button type="button" className="btn" onClick={load}>Refresh</button>
      </form>
      <pre className="log terminal-out">{d ? d.log || 'Nothing logged yet.' : 'Loading...'}</pre>
    </Box>
  )
}

function TimeSwap() {
  const [d, , err, setErr, setD] = useGet('/plus/time')
  const [msg, run] = useRun(setErr)
  const [tz, setTz] = useState('')
  const [mb, setMb] = useState('')
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <Box err={err} msg={msg}>
      <h3>Time</h3>
      <p className="muted small">Now: {new Date(d.now).toLocaleString()} - time zone {d.timezone} - clock {d.synced ? 'synchronised' : 'not synchronised'}.</p>
      <div className="row" style={{ alignItems: 'flex-end' }}>
        <label style={{ minWidth: 240 }}>Time zone<select value={tz || d.timezone} onChange={(e) => setTz(e.target.value)} data-no-translate>{d.zones.map((z) => <option key={z}>{z}</option>)}</select></label>
        <button className="btn primary" onClick={() => run(api('/plus/time', { timezone: tz || d.timezone }), 'Time zone saved.', setD)}>Save</button>
        <button className="btn" onClick={() => run(api('/plus/time', { ntp: !d.ntp }), d.ntp ? 'Time sync off.' : 'Time sync on.', setD)}>{d.ntp ? 'Stop time sync' : 'Keep the clock in sync'}</button>
      </div>
      <h3>Swap</h3>
      <p className="muted small">Extra memory on the disk for busy moments. {d.swap.length ? 'Now: ' + d.swap.map((s) => s.file + ' ' + bytes(s.size) + ' (' + bytes(s.used) + ' used)').join(', ') : 'Now: no swap.'}</p>
      <div className="row" style={{ alignItems: 'flex-end' }}>
        <label style={{ width: 200 }}>Swap file size (MB)<input type="number" min="0" max="65536" value={mb} onChange={(e) => setMb(e.target.value)} placeholder={String(d.swap_mb || 2048)} /></label>
        <button className="btn primary" onClick={() => run(api('/plus/swap', { mb: Number(mb || 2048) }), 'Swap file ready.', setD)}>Set</button>
        {d.swap.length > 0 && <button className="btn" onClick={() => window.confirm('Remove the swap file?') && run(api('/plus/swap', { mb: 0 }), 'Swap removed.', setD)}>Remove</button>}
      </div>
    </Box>
  )
}

function Jails() {
  const [d, , err, setErr, setD] = useGet('/plus/jails')
  const [msg, run] = useRun(setErr)
  const [s, setS] = useState(null)
  const [ban, setBan] = useState({ jail: '', ip: '' })
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const v = s || d.settings
  return (
    <Box err={err} msg={msg}>
      <p className="muted small">Fail2ban blocks an address for a while after too many wrong passwords (SSH, mail, the panel ...).</p>
      {d.jails.length === 0 && <p className="muted">No jails are running. Install Fail2ban in Software.</p>}
      {d.jails.map((j) => <div key={j.name} className="sub-card">
        <h4>{j.name} <span className="muted small">{j.banned} blocked now, {j.total} so far, {j.failed} failing</span></h4>
        <div className="row">{j.ips.map((ip) => <span key={ip} className="pill">{ip} <button className="link" onClick={() => run(api('/plus/jails/' + j.name + '/unban', { ip }), ip + ' unblocked.', setD)}>Unblock</button></span>)}</div>
      </div>)}
      {d.jails.length > 0 && <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/plus/jails/' + (ban.jail || d.jails[0].name) + '/ban', { ip: ban.ip }), ban.ip + ' blocked.', setD) }}>
        <label>Jail<select value={ban.jail} onChange={(e) => setBan({ ...ban, jail: e.target.value })}>{d.jails.map((j) => <option key={j.name}>{j.name}</option>)}</select></label>
        <label style={{ minWidth: 200 }}>IP address<input value={ban.ip} onChange={(e) => setBan({ ...ban, ip: e.target.value })} required /></label><button className="btn">Block</button></form>}
      <h3>Rules for every jail</h3>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/plus/jails/settings', v), 'Saved.', (r) => { setD(r); setS(null) }) }}>
        <label style={{ width: 150 }}>Block for (seconds)<input type="number" value={v.bantime} onChange={(e) => setS({ ...v, bantime: e.target.value })} /></label>
        <label style={{ width: 150 }}>Within (seconds)<input type="number" value={v.findtime} onChange={(e) => setS({ ...v, findtime: e.target.value })} /></label>
        <label style={{ width: 130 }}>Wrong tries<input type="number" value={v.maxretry} onChange={(e) => setS({ ...v, maxretry: e.target.value })} /></label>
        <label style={{ minWidth: 220 }}>Never block<input value={v.ignore} onChange={(e) => setS({ ...v, ignore: e.target.value })} placeholder="203.0.113.7 or 203.0.113.0/24" /></label>
        <button className="btn primary">Save</button>
      </form>
    </Box>
  )
}

function Countries() {
  const [d, , err, setErr, setD] = useGet('/plus/countries')
  const [msg, run] = useRun(setErr)
  const [v, setV] = useState(null)
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const val = v ?? d.countries.join(' ')
  return (
    <Box err={err} msg={msg}>
      <p className="muted small">Connections from these countries are dropped by the firewall (web, mail, SSH). The lists are refreshed every night. Your own address always stays allowed{d.allow.length ? ': ' + d.allow.join(', ') : ''}.</p>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/plus/countries', { countries: val }), 'Saved.', (r) => { setD(r); setV(null) }) }}>
        <label style={{ minWidth: 260 }}>Countries (two-letter codes)<input value={val} onChange={(e) => setV(e.target.value)} placeholder="cn ru kp" /></label>
        <button className="btn primary">Save</button>
      </form>
    </Box>
  )
}

function Access() {
  const [d, , err, setErr, setD] = useGet('/plus/access')
  const [msg, run] = useRun(setErr)
  const [v, setV] = useState(null)
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const val = v || { allow_ips: d.allow_ips.join(' '), session_hours: d.session_hours }
  return (
    <Box err={err} msg={msg}>
      <form onSubmit={(e) => { e.preventDefault(); run(api('/plus/access', val), 'Saved.', (r) => { setD(r); setV(null) }) }}>
        <label>Sign-in only from these addresses (empty: from anywhere)<input value={val.allow_ips} onChange={(e) => setV({ ...val, allow_ips: e.target.value })} placeholder={d.your_ip + ' 203.0.113.0/24'} /></label>
        <p className="muted small">Your address now: {d.your_ip}. It must be in the list.</p>
        <label style={{ maxWidth: 260 }}>Signed in for at most (hours)<input type="number" min="1" max="720" value={val.session_hours} onChange={(e) => setV({ ...val, session_hours: e.target.value })} /></label>
        <button className="btn primary">Save</button>
      </form>
      <Sso />
      <h3>Mail on a new sign-in</h3>
      <p className="muted small">Each user gets a mail (to the address of their account) when they sign in from an address they did not use before. Now: {d.login_alert ? 'on' : 'off'}.</p>
      <button className="btn" onClick={() => run(api('/plus/access', { login_alert: !d.login_alert }), 'Saved.', setD)}>{d.login_alert ? 'Switch off' : 'Switch on'}</button>
    </Box>
  )
}

function Rclone() {
  const [d, , err, setErr, setD] = useGet('/plus/rclone')
  const [msg, run] = useRun(setErr)
  const [f, setF] = useState({ type: 'sftp', host: '', port: '', user: '', password: '', token: '', folder: 'zarspanel-backups' })
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const names = { sftp: 'SFTP server', ftp: 'FTP server', webdav: 'WebDAV (Nextcloud ...)', drive: 'Google Drive', dropbox: 'Dropbox', onedrive: 'OneDrive', b2: 'Backblaze B2', pcloud: 'pCloud', mega: 'MEGA' }
  const login = ['sftp', 'ftp', 'webdav'].includes(f.type)
  const oauth = ['drive', 'dropbox', 'onedrive', 'pcloud'].includes(f.type)
  return (
    <Box err={err} msg={msg}>
      <p className="muted small">Every backup is also copied here (next to S3 storage, if set). {d.remote ? 'Now: ' + (names[d.remote.type] || d.remote.type) + (d.remote.host ? ' ' + d.remote.host : '') + ', folder ' + d.remote.folder : 'Now: not set.'}</p>
      {d.remote && <div className="row"><button className="btn" onClick={() => run(api('/plus/rclone/test', {}), '', (r) => { if (!r.ok) setErr(r.message); else setErr('') }).then((r) => r && r.ok && run(Promise.resolve(r), r.message))}>Test</button>
        <button className="btn" onClick={() => window.confirm('Stop copying backups there?') && run(api('/plus/rclone', { type: '' }), 'Removed.', setD)}>Remove</button></div>}
      <form className="form" style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); run(api('/plus/rclone', f), 'Saved. Press Test to check it.', setD) }}>
        <label>Where<select value={f.type} onChange={set('type')}>{d.types.map((t) => <option key={t} value={t}>{names[t] || t}</option>)}</select></label>
        <label>Folder there<input value={f.folder} onChange={set('folder')} /></label>
        {login && <><label>{f.type === 'webdav' ? 'Address (https://...)' : 'Server'}<input value={f.host} onChange={set('host')} required /></label>{f.type !== 'webdav' && <label>Port<input type="number" value={f.port} onChange={set('port')} placeholder={f.type === 'sftp' ? '22' : '21'} /></label>}</>}
        {(login || f.type === 'b2' || f.type === 'mega') && <><label>{f.type === 'b2' ? 'Key ID' : 'User'}<input value={f.user} onChange={set('user')} required /></label><label>{f.type === 'b2' ? 'Application key' : 'Password'}<input type="password" autoComplete="new-password" value={f.password} onChange={set('password')} /></label></>}
        {oauth && <label className="full">Token<textarea rows="3" value={f.token} onChange={set('token')} placeholder='{"access_token":"...","refresh_token":"..."}' required />
          <span className="muted small">On your own computer install rclone and run: rclone authorize "{f.type}". Sign in in the browser it opens, then paste the token it prints here.</span></label>}
        <div className="full"><button className="btn primary">Save</button></div>
      </form>
    </Box>
  )
}

// ---- one Linux user per hosting account (0.13.0) ------------------------------------------------------------------------------
function SiteUsers() {
  const [d, load, err, setErr, setD] = useGet('/plus/site-users')
  const [msg, run] = useRun(setErr)
  const [busy, setBusy] = useState(false)
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const move = (body) => { setBusy(true); run(api('/plus/site-users/move', body), '', (r) => setD(r)).then((r) => { setBusy(false); if (r) run(Promise.resolve(r), r.moved.length + ' site(s) moved to their own user.') }) }
  return (
    <Box err={err} msg={msg}>
      <h3>One Linux user per hosting account</h3>
      <p className="muted small">Each account's sites run as its own user (zpu + account number) with its own PHP pool: a hacked site cannot read the files of another account. Files, FTP, cron jobs, Git, Composer, WordPress / Laravel tools and background programs run as that user too. Needed for CloudLinux limits and real disk quotas.</p>
      <label className="check-row"><input type="checkbox" checked={d.enabled} onChange={(e) => run(api('/plus/site-users', { enabled: e.target.checked }), e.target.checked ? 'New sites get their account user.' : 'New sites run as www-data (not recommended).', setD)} /> Give new sites their account's own user</label>
      {d.legacy > 0
        ? <div className="alert" role="note">{d.legacy} site(s) made before this version still run as www-data. <button className="btn primary" disabled={busy} onClick={() => move({})}>{busy ? 'Moving...' : 'Move all sites'}</button><br /><small>Files change owner, the PHP pool, Nginx, FTP accounts, cron jobs and background programs follow. The sites keep working; a big site may take a minute.</small></div>
        : <p className="small">Every site runs as its account's own user.</p>}
      {d.sites.length === 0 ? <p className="muted small">No websites yet.</p> : <div className="wrap"><table className="table">
        <thead><tr><th>Site</th><th>Account</th><th>Runs as</th><th /></tr></thead>
        <tbody>{d.sites.map((s) => <tr key={s.id}><td data-label="Site">{s.domain}</td><td data-label="Account">{s.account || '-'}</td><td data-label="Runs as">{s.user}{s.own ? '' : ' (shared)'}</td>
          <td>{!s.own && <button className="btn" disabled={busy} onClick={() => move({ site_id: s.id })}>Move</button>}</td></tr>)}</tbody>
      </table></div>}
    </Box>
  )
}

function Cluster() {
  const [d, load, err, setErr] = useGet('/plus/dns-cluster')
  const [msg, run] = useRun(setErr)
  const [sec, setSec] = useState(null)
  const [pri, setPri] = useState({ ip: '', zones: '' })
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <Box err={err} msg={msg}>
      <h3>Other servers answer for my zones (this server is the primary)</h3>
      <p className="muted small">These DNS servers may copy every zone of this server and are told at once when a zone changes ({d.zones.length} zones).</p>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/plus/dns-cluster/secondaries', { ips: sec ?? d.secondaries.join(' ') }), 'Saved.', () => { setSec(null); load() }) }}>
        <label style={{ minWidth: 280 }}>IP addresses of the other DNS servers<input value={sec ?? d.secondaries.join(' ')} onChange={(e) => setSec(e.target.value)} placeholder="203.0.113.20 203.0.113.21" /></label><button className="btn primary">Save</button>
      </form>
      <h3>This server answers for the zones of another server (secondary)</h3>
      {d.primaries.map((p, i) => <p key={i} className="small">{p.ip}: {p.zones.join(', ') || '-'} <button className="link" onClick={() => run(api('/plus/dns-cluster/primaries', { primaries: d.primaries.filter((_, j) => j !== i) }), 'Removed.', load)}>Remove</button></p>)}
      <form className="form" onSubmit={(e) => { e.preventDefault(); run(api('/plus/dns-cluster/primaries', { primaries: [...d.primaries, { ip: pri.ip, zones: pri.zones.split(/[\s,]+/).filter(Boolean) }] }), 'Saved.', () => { setPri({ ip: '', zones: '' }); load() }) }}>
        <label>IP address of the primary<input value={pri.ip} onChange={(e) => setPri({ ...pri, ip: e.target.value })} required /></label>
        <label className="full">Its zones (the list is in its own DNS cluster tab)<textarea rows="2" value={pri.zones} onChange={(e) => setPri({ ...pri, zones: e.target.value })} placeholder="example.com shop.example.org" /></label>
        <div className="full"><button className="btn">Add</button></div>
      </form>
    </Box>
  )
}

// ---- website "Tools" tab -------------------------------------------------------------------------------------------------------
export function SiteToolsPlus({ s }) {
  const [part, setPart] = useState('wordpress')
  const parts = [['wordpress', 'WordPress'], ['laravel', 'Laravel'], ['workers', 'Background programs'], ['staging', 'Staging copy'], ['speed', 'Speed and protection']]
  return (
    <>
      <div className="tabs" role="tablist">{parts.map(([k, l]) => <button key={k} role="tab" aria-selected={part === k} onClick={() => setPart(k)}>{l}</button>)}</div>
      {part === 'wordpress' && <WordPress s={s} />}{part === 'laravel' && <Laravel s={s} />}{part === 'workers' && <Workers s={s} />}{part === 'staging' && <Staging s={s} />}{part === 'speed' && <Speed s={s} />}
    </>
  )
}

function WordPress({ s }) {
  const [d, load, err, setErr, setD] = useGet('/sites/' + s.id + '/wordpress')
  const [msg, run] = useRun(setErr)
  const [busy, setBusy] = useState(false)
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  if (!d.wordpress) return <div className="card"><p className="muted">WordPress is not installed in this website. Install it from the App store.</p></div>
  const act = (action, name, m) => { setBusy(true); run(api('/sites/' + s.id + '/wordpress', { action, name }), m, setD).finally(() => setBusy(false)) }
  const list = (items, kind) => (
    <div className="wrap"><table className="table"><thead><tr><th>Name</th><th>Status</th><th>Version</th><th /></tr></thead><tbody>
      {items.map((p) => <tr key={p.name}><td>{p.name}</td><td>{p.status}</td><td>{p.version}{p.update === 'available' && <span className="pill warn"> {p.update_version}</span>}</td>
        <td className="row">{p.update === 'available' && <button className="btn sm" disabled={busy} onClick={() => act('update-' + kind, p.name, p.name + ' updated.')}>Update</button>}
          {kind === 'plugins' && (p.status === 'active' ? <button className="btn sm" disabled={busy} onClick={() => act('plugin-off', p.name, p.name + ' switched off.')}>Switch off</button> : <button className="btn sm" disabled={busy} onClick={() => act('plugin-on', p.name, p.name + ' switched on.')}>Switch on</button>)}</td></tr>)}
    </tbody></table></div>
  )
  return (
    <Box err={err} msg={msg}>
      <p>WordPress {d.version}{d.core_update ? ' - version ' + d.core_update + ' is out.' : ' - up to date.'}</p>
      <div className="row">
        <button className="btn primary" disabled={busy} onClick={() => act('update-all', '', 'Everything is up to date.')}>{busy ? 'Working...' : 'Update everything'}</button>
        {d.core_update && <button className="btn" disabled={busy} onClick={() => act('update-core', '', 'WordPress updated.')}>Update WordPress</button>}
        <button className="btn" disabled={busy} onClick={() => act(d.maintenance_on ? 'maintenance-off' : 'maintenance-on', '', d.maintenance_on ? 'Visitors see the site again.' : 'Visitors see a maintenance page.')}>{d.maintenance_on ? 'End maintenance' : 'Maintenance mode'}</button>
        <button className="btn" disabled={busy} onClick={() => act('harden', '', 'Safer settings applied: no file editing in the dashboard, no debug output, wp-config.php locked.')}>Make it safer</button>
        <button className="btn" disabled={busy} onClick={() => act('cache-flush', '', 'Cache cleared.')}>Clear cache</button>
        <button className="btn" disabled={busy} onClick={() => act('check', '', 'Checked the WordPress files against the official ones.')}>Check files</button>
      </div>
      {msg && d.output && <pre className="log terminal-out">{d.output}</pre>}
      <h3>Plugins</h3>{list(d.plugins, 'plugins')}
      <h3>Themes</h3>{list(d.themes, 'themes')}
      <h3>Administrators</h3>
      {d.users.map((u) => <p key={u.ID} className="row">{u.user_login} <span className="muted small">{u.user_email}</span>
        <button className="btn sm" onClick={() => window.confirm('Make a new password for ' + u.user_login + '?') && run(api('/sites/' + s.id + '/wordpress/password', { user: u.user_login }), '', (r) => showValue('New password (copy it now):', r.password))}>New password</button></p>)}
    </Box>
  )
}

function Laravel({ s }) {
  const [d, load, err, setErr] = useGet('/sites/' + s.id + '/laravel')
  const [msg, run] = useRun(setErr)
  const [cmd, setCmd] = useState('optimize')
  const [out, setOut] = useState('')
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <Box err={err} msg={msg}>
      <div className="row" style={{ alignItems: 'flex-end' }}>
        <label style={{ minWidth: 220 }}>Artisan command<select value={cmd} onChange={(e) => setCmd(e.target.value)} data-no-translate>{d.commands.map((c) => <option key={c}>{c}</option>)}</select></label>
        <button className="btn primary" onClick={() => run(api('/sites/' + s.id + '/artisan', { command: cmd }), 'Done.', (r) => setOut(r.output))}>Run</button>
      </div>
      {out && <pre className="log terminal-out">{out}</pre>}
      <h3>Scheduler</h3>
      <p className="muted small">Runs "php artisan schedule:run" every minute. Now: {d.scheduler ? 'on' : 'off'}.</p>
      <button className="btn" onClick={() => run(api('/sites/' + s.id + '/scheduler', { on: !d.scheduler }), 'Saved.', load)}>{d.scheduler ? 'Switch off' : 'Switch on'}</button>
      <p className="muted small">Queue workers: add "php artisan queue:work" under Background programs.</p>
    </Box>
  )
}

function Workers({ s }) {
  const [d, , err, setErr, setD] = useGet('/sites/' + s.id + '/workers')
  const [msg, run] = useRun(setErr)
  const [f, setF] = useState({ name: 'Queue', command: 'php artisan queue:work --sleep=3 --tries=3', procs: 1 })
  const [log, setLog] = useState(null)
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const act = (w, a) => run(api('/sites/' + s.id + '/workers/' + w.id + '/' + a, {}), w.name + ': ' + a + ' done.', setD)
  return (
    <Box err={err} msg={msg}>
      <p className="muted small">Commands that keep running (queue workers, Node.js scripts, bots). They start again by themselves when they stop or the server restarts.</p>
      {d.workers.length > 0 && <div className="wrap"><table className="table"><thead><tr><th>Name</th><th>Command</th><th>State</th><th /></tr></thead><tbody>
        {d.workers.map((w) => <tr key={w.id}><td>{w.name} ({w.procs})</td><td><span className="code">{w.command}</span></td><td><span className={'pill ' + (w.state === 'RUNNING' ? 'on' : 'off')}>{w.state}</span></td>
          <td className="row"><button className="btn sm" onClick={() => act(w, 'restart')}>Restart</button><button className="btn sm" onClick={() => act(w, w.state === 'RUNNING' ? 'stop' : 'start')}>{w.state === 'RUNNING' ? 'Stop' : 'Start'}</button>
            <button className="btn sm" onClick={() => api('/sites/' + s.id + '/workers/' + w.id + '/log').then((r) => setLog(r.log || 'Nothing logged yet.')).catch((e) => setErr(e.message))}>Log</button>
            <button className="btn sm danger" onClick={() => window.confirm('Remove ' + w.name + '?') && act(w, 'delete')}>Remove</button></td></tr>)}
      </tbody></table></div>}
      {log !== null && <><pre className="log terminal-out">{log}</pre><button className="btn sm" onClick={() => setLog(null)}>Close</button></>}
      <form className="form" style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); run(api('/sites/' + s.id + '/workers', f), 'Started.', setD) }}>
        <label>Name<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></label>
        <label>How many copies<input type="number" min="1" max="8" value={f.procs} onChange={(e) => setF({ ...f, procs: e.target.value })} /></label>
        <label className="full">Command (runs in the website folder)<input className="code" value={f.command} onChange={(e) => setF({ ...f, command: e.target.value })} required /></label>
        <div className="full"><button className="btn primary">Add and start</button></div>
      </form>
    </Box>
  )
}

function Staging({ s }) {
  const [err, setErr] = useState('')
  const [msg, run] = useRun(setErr)
  const [busy, setBusy] = useState(false)
  const isCopy = s.domain.startsWith('staging.')
  const go = (p, m) => { setBusy(true); run(p, m).finally(() => setBusy(false)) }
  return (
    <Box err={err} msg={msg}>
      {isCopy ? <>
        <p>This is the staging copy of {s.domain.slice(8)}. Try changes here, then push them to the live site. A backup of the live site is made first.</p>
        <div className="row">
          <button className="btn primary" disabled={busy} onClick={() => window.confirm('Copy the files of this copy to ' + s.domain.slice(8) + '?') && go(api('/sites/' + s.id + '/push-live', { database: false }), 'The files are live.')}>Push the files</button>
          <button className="btn" disabled={busy} onClick={() => window.confirm('Copy the files AND the database to ' + s.domain.slice(8) + '? Orders, comments or sign-ups made on the live site since the copy are replaced.') && go(api('/sites/' + s.id + '/push-live', { database: true }), 'Files and database are live.')}>Push files and database</button>
        </div>
      </> : <>
        <p>Make staging.{s.domain}: a copy of the files and databases of this site where changes can be tried first. WordPress and Laravel settings point to the copy; search engines are kept away.</p>
        <p className="muted small">Point staging.{s.domain} to this server in DNS (or switch on the preview address of the copy).</p>
        <button className="btn primary" disabled={busy} onClick={() => go(api('/sites/' + s.id + '/staging', {}), 'Made staging.' + s.domain + '. Open it in Websites.')}>{busy ? 'Copying...' : 'Make a staging copy'}</button>
      </>}
    </Box>
  )
}

function Speed({ s }) {
  const [d, load, err, setErr] = useGet('/sites/' + s.id + '/tools')
  const [p] = useGet('/sites/' + s.id + '/preview')
  const [msg, run] = useRun(setErr)
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const t = d.tools
  const ols = t.webserver === 'ols'
  const flip = (k, m) => run(api('/sites/' + s.id + '/tools', { [k]: !t[k] }), m, load)
  return (
    <Box err={err} msg={msg}>
      {s.type === 'php' && <>
        <h3>Web server</h3>
        <p className="muted small">Nginx with PHP-FPM (default), or OpenLiteSpeed: LiteSpeed's LSAPI PHP, .htaccess rules like on LiteSpeed hosting, and LSCache - the page cache the LiteSpeed Cache plugin of WordPress uses. HTTPS and the other site tools stay the same. Now: {ols ? 'OpenLiteSpeed' : 'Nginx'}.</p>
        {!d.ols_installed && !ols ? <p className="muted small">Install OpenLiteSpeed first: Server &gt; Software &gt; OpenLiteSpeed.</p> : <div className="row">
          <button className="btn" onClick={() => run(api('/sites/' + s.id + '/tools', { webserver: ols ? 'nginx' : 'ols' }), ols ? 'Back on Nginx.' : 'Now on OpenLiteSpeed. In WordPress install the LiteSpeed Cache plugin.', load)}>{ols ? 'Use Nginx' : 'Use OpenLiteSpeed'}</button>
          {ols && <button className="btn" onClick={() => run(api('/sites/' + s.id + '/lscache-purge', {}), 'LSCache emptied.')}>Empty LSCache</button>}
        </div>}
      </>}
      {!ols && <>
      <h3>Page cache</h3>
      <p className="muted small">Pages are kept for 10 minutes and sent without running PHP, so the site is much faster. Never for signed-in visitors, forms or carts. Now: {t.fcache ? 'on' : 'off'}.</p>
      <div className="row"><button className="btn" onClick={() => flip('fcache', 'Saved.')}>{t.fcache ? 'Switch off' : 'Switch on'}</button>{t.fcache && <button className="btn" onClick={() => run(api('/sites/' + s.id + '/cache/purge', {}), 'Cache cleared.')}>Clear the cache</button>}</div>
      </>}
      <h3>Block bad bots</h3>
      <p className="muted small">Refuses known scrapers and attack tools (SEO crawlers that eat bandwidth, sqlmap, nikto ...). Now: {t.block_bots ? 'on' : 'off'}.</p>
      <button className="btn" onClick={() => flip('block_bots', 'Saved.')}>{t.block_bots ? 'Switch off' : 'Switch on'}</button>
      <h3>Preview address</h3>
      <p className="muted small">Open the site before the domain points to this server. {t.preview && p ? <>Address: <a href={'http://' + p.name} target="_blank" rel="noreferrer">{p.name}</a></> : 'Now: off.'}</p>
      <button className="btn" onClick={() => flip('preview', 'Saved.')}>{t.preview ? 'Switch off' : 'Switch on'}</button>
    </Box>
  )
}

// ---- file manager additions: recycle bin and share links -----------------------------------------------------------------------
export function RecycleBin({ s, onClose, onChange }) {
  const [d, , err, setErr, setD] = useGet('/sites/' + s.id + '/trash')
  const [msg, run] = useRun(setErr)
  const act = (key, a, m) => run(api('/sites/' + s.id + '/trash/' + key + '/' + a, {}), m, (r) => { setD(r); onChange && onChange() })
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Recycle bin"><div className="card" style={{ width: 'min(800px,100%)' }}>
      <h2>Recycle bin</h2>
      <p className="muted small">Deleted files wait here for 30 days.</p>
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}
      {!d ? <p className="muted">Loading...</p> : d.trash.length === 0 ? <p className="muted">The recycle bin is empty.</p> : (
        <div className="wrap"><table className="table"><thead><tr><th>File</th><th>Deleted</th><th>Size</th><th /></tr></thead><tbody>
          {d.trash.map((t) => <tr key={t.key}><td>{t.path}</td><td>{when(t.at)}</td><td>{bytes(t.size)}</td>
            <td className="row"><button className="btn sm" onClick={() => act(t.key, 'restore', t.path + ' is back.')}>Restore</button><button className="btn sm danger" onClick={() => window.confirm('Delete ' + t.path + ' for good?') && act(t.key, 'delete', 'Deleted for good.')}>Delete for good</button></td></tr>)}
        </tbody></table></div>
      )}
      <div className="row" style={{ marginTop: 10 }}>{d && d.trash.length > 0 && <button className="btn danger" onClick={() => window.confirm('Empty the recycle bin?') && act('*', 'empty', 'Emptied.')}>Empty the recycle bin</button>}<button className="btn" onClick={onClose}>Close</button></div>
    </div></div>
  )
}

export async function shareFile(s, path, setErr) {
  const h = await ask('Anyone with the link can download ' + path + '. For how many hours?', '24')
  if (!h) return
  api('/sites/' + s.id + '/shares', { path, hours: Number(h) }).then((r) => showValue('Share link (copy it now):', window.location.origin + r.url)).catch((e) => setErr(e.message))
}

// ---- pages ------------------------------------------------------------------------------------------------------------------------
export function Uptime() {
  const [d, , err, setErr, setD] = useGet('/plus/uptime')
  const [msg, run] = useRun(setErr)
  return (
    <>
      <h1>Uptime monitor</h1>
      <Box err={err} msg={msg}>
        <p className="muted small">Each watched website is opened every 5 minutes. When it stops answering (or comes back) you get an alert (Settings &gt; Alerts).</p>
        {!d ? <p className="muted">Loading...</p> : d.sites.length === 0 ? <p className="muted">No websites yet.</p> : (
          <div className="wrap"><table className="table"><thead><tr><th>Website</th><th>Now</th><th>Answer</th><th>30 days</th><th>Checked</th><th /></tr></thead><tbody>
            {d.sites.map((x) => <tr key={x.id}><td>{x.domain}</td>
              <td>{!x.on ? <span className="muted">not watched</span> : x.checked_at ? <span className={'pill ' + (x.up ? 'on' : 'off')}>{x.up ? 'up' : 'down'}</span> : <span className="muted">waiting</span>}</td>
              <td>{x.on && x.checked_at ? x.code + ' in ' + x.ms + ' ms' : '-'}</td><td>{x.percent != null ? x.percent + '%' : '-'}</td><td className="small">{x.on ? when(x.checked_at) : '-'}</td>
              <td><button className="btn sm" onClick={() => run(api('/plus/uptime/' + x.id, { on: !x.on }), x.on ? 'Not watched any more.' : 'Watched from now on.', setD)}>{x.on ? 'Stop' : 'Watch'}</button></td></tr>)}
          </tbody></table></div>
        )}
      </Box>
    </>
  )
}

export function MoveFromServer() {
  const [sites] = useGet('/sites')
  const [dbs] = useGet('/databases')
  const [err, setErr] = useState('')
  const [job, setJob] = useState(null)
  const [log, setLog] = useState(null)
  const [f, setF] = useState({ site_id: '', host: '', port: 22, user: '', password: '', path: '', db_id: '', remote_db: '', remote_db_user: '', remote_db_password: '' })
  useEffect(() => {
    if (!job) return
    const t = setInterval(() => api('/plus/migrate/' + job).then((r) => { setLog(r.log); if (r.done) clearInterval(t) }).catch(() => {}), 3000)
    return () => clearInterval(t)
  }, [job])
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  return (
    <>
      <h1>Move a website from another server</h1>
      <div className="card">
        <p className="muted small">From any server you can reach over SSH (another panel, a VPS, shared hosting with SSH): the files are copied with rsync and the database through SSH. First add the website (and its database) here, then fill this in.</p>
        {err && <div className="alert bad">{err}</div>}
        <form className="form" onSubmit={(e) => { e.preventDefault(); setErr(''); api('/plus/migrate', f).then((r) => { setJob(r.job); setLog('Starting...') }).catch((x) => setErr(x.message)) }}>
          <label>Into this website<select value={f.site_id} onChange={set('site_id')} required><option value="">Choose...</option>{sites && sites.sites.map((s) => <option key={s.id} value={s.id}>{s.domain}</option>)}</select></label>
          <label>Old server<input value={f.host} onChange={set('host')} placeholder="old.example.com" required /></label>
          <label>SSH port<input type="number" value={f.port} onChange={set('port')} /></label>
          <label>SSH user<input value={f.user} onChange={set('user')} required /></label>
          <label>SSH password (empty: this server's SSH key is allowed there)<input type="password" autoComplete="new-password" value={f.password} onChange={set('password')} /></label>
          <label>Folder of the website there<input value={f.path} onChange={set('path')} placeholder="/home/shop/public_html" required /></label>
          <label>Into this database (optional)<select value={f.db_id} onChange={set('db_id')}><option value="">No database</option>{dbs && dbs.databases.filter((d) => d.engine !== 'pgsql').map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select></label>
          {f.db_id && <><label>Database name there<input value={f.remote_db} onChange={set('remote_db')} required /></label><label>Database user there<input value={f.remote_db_user} onChange={set('remote_db_user')} required /></label>
            <label>Database password there<input type="password" autoComplete="new-password" value={f.remote_db_password} onChange={set('remote_db_password')} /></label></>}
          <div className="full"><button className="btn primary" disabled={!!job && !(log || '').match(/FINISHED|STOPPED/)}>Move it</button></div>
        </form>
        {log !== null && <pre className="log terminal-out">{log}</pre>}
      </div>
    </>
  )
}

export function Cloudflare() {
  const [d, load, err, setErr] = useGet('/plus/cloudflare')
  const [zones] = useGet('/dns')
  const [msg, run] = useRun(setErr)
  const [token, setToken] = useState('')
  return (
    <>
      <h1>Cloudflare</h1>
      <Box err={err} msg={msg}>
        <p className="muted small">Send the DNS records of your zones to Cloudflare (for its CDN and protection). Make an API token in Cloudflare: My Profile &gt; API Tokens &gt; "Edit zone DNS".</p>
        {d && (d.connected ? <p>Connected. <button className="link" onClick={() => run(api('/plus/cloudflare/token', { token: '' }), 'Disconnected.', load)}>Disconnect</button></p> : (
          <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/plus/cloudflare/token', { token }), 'Connected.', () => { setToken(''); load() }) }}>
            <label style={{ minWidth: 300 }}>API token<input type="password" autoComplete="off" value={token} onChange={(e) => setToken(e.target.value)} required /></label><button className="btn primary">Connect</button>
          </form>
        ))}
        {d && d.connected && zones && <div className="wrap"><table className="table"><thead><tr><th>Zone</th><th /></tr></thead><tbody>
          {(zones.zones || []).map((z) => <tr key={z.id}><td>{z.domain}</td><td><button className="btn sm" onClick={() => run(api('/plus/cloudflare/sync/' + z.id, {}), '', (r) => run(Promise.resolve(r), z.domain + ': ' + r.made + ' records added, ' + r.updated + ' changed at Cloudflare.'))}>Send to Cloudflare</button></td></tr>)}
        </tbody></table></div>}
      </Box>
    </>
  )
}

// ---- ImunifyAV / Imunify360 ---------------------------------------------------------------------------------------------------------
function ImunifyTab() {
  const [d, load, err, setErr] = useGet('/plus/imunify')
  const [msg, run] = useRun(setErr)
  const [key, setKey] = useState('')
  const [found, setFound] = useState(null)
  const [domain, setDomain] = useState('')
  const [pick, setPick] = useState({})
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const ready = d.av || d.i360
  const loadFound = () => api('/plus/imunify/found').then(setFound).catch((e) => setErr(e.message))
  const ids = Object.keys(pick).filter((k) => pick[k])
  return (
    <Box err={err} msg={msg}>
      <p className="muted small">Malware scanners from CloudLinux, as other panels offer them. ImunifyAV is free; Imunify360 adds a firewall and proactive defence and needs its licence key.</p>
      <p>{d.i360 ? 'Imunify360 ' + d.i360 + ' is installed.' : d.av ? 'ImunifyAV ' + d.av + ' is installed.' : 'Not installed.'}</p>
      {!ready && !d.installing && <div className="row" style={{ alignItems: 'flex-end' }}>
        <button className="btn primary" onClick={() => run(api('/plus/imunify/install', { product: 'av' }), 'Installing ImunifyAV in the background.', load)}>Install ImunifyAV (free)</button>
        <label style={{ minWidth: 220 }}>Imunify360 licence key<input value={key} onChange={(e) => setKey(e.target.value)} autoComplete="off" /></label>
        <button className="btn" disabled={!key} onClick={() => run(api('/plus/imunify/install', { product: '360', key }), 'Installing Imunify360 in the background.', load)}>Install Imunify360</button>
      </div>}
      {(d.installing || d.log) && !ready && <><pre className="log terminal-out">{d.log}</pre><button className="btn sm" onClick={load}>Refresh</button></>}
      {ready && <>
        <div className="row" style={{ alignItems: 'flex-end' }}>
          <label style={{ minWidth: 240 }}>Website (empty: all)<input value={domain} onChange={(e) => setDomain(e.target.value)} placeholder="shop.example.com" /></label>
          <button className="btn primary" onClick={() => run(api('/plus/imunify/scan', { domain }), 'The scan started. Look at the results in a few minutes.')}>Scan</button>
          <button className="btn" onClick={loadFound}>Show the results</button>
        </div>
        {found && (found.found.length === 0 ? <p className="muted">Nothing harmful found.</p> : <>
          <div className="wrap"><table className="table"><thead><tr><th /><th>File</th><th>Kind</th><th>State</th></tr></thead><tbody>
            {found.found.map((f) => <tr key={f.id}><td><input type="checkbox" aria-label={'Choose ' + f.file} checked={!!pick[f.id]} onChange={(e) => setPick({ ...pick, [f.id]: e.target.checked })} /></td><td className="small" data-no-translate>{f.file}</td><td>{f.type}</td><td>{f.status}</td></tr>)}
          </tbody></table></div>
          <div className="row"><button className="btn primary" disabled={!ids.length} onClick={() => run(api('/plus/imunify/cleanup', { ids }), 'Cleaned.', setFound)}>Clean</button>
            <button className="btn" disabled={!ids.length} onClick={() => run(api('/plus/imunify/restore-original', { ids }), 'Restored.', setFound)}>Restore the original</button>
            <button className="btn" disabled={!ids.length} onClick={() => run(api('/plus/imunify/move-to-ignore', { ids }), 'Ignored from now on.', setFound)}>Ignore</button></div>
        </>)}
      </>}
    </Box>
  )
}

// ---- password strength and fork-bomb protection (Webuzo) -------------------------------------------------------------------------------
function Limits() {
  const [st, loadSt, err, setErr] = useGet('/plus/strength')
  const [fb, loadFb] = useGet('/plus/fork-bomb')
  const [msg, run] = useRun(setErr)
  if (!st || !fb) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const levels = [[1, 'Normal: 10 characters with letters and digits'], [2, 'Strong: 12 characters with small and capital letters and digits'], [3, 'Very strong: 14 characters with small and capital letters, digits and a sign']]
  return (
    <Box err={err} msg={msg}>
      <h3>Password strength</h3>
      <p className="muted small">New passwords of every user of the panel must have at least:</p>
      {levels.map(([n, l]) => <label key={n} className="check-row"><input type="radio" name="strength" checked={st.level === n} onChange={() => run(api('/plus/strength', { level: n }), 'Saved.', loadSt)} /> {l}</label>)}
      <h3>Fork bomb protection</h3>
      <p className="muted small">A runaway script cannot start thousands of programs and stop the server: at most 3000 programs per Linux user (root is not limited). Now: {fb.on ? 'on' : 'off'}.</p>
      <button className="btn" onClick={() => run(api('/plus/fork-bomb', { on: !fb.on }), 'Saved.', loadFb)}>{fb.on ? 'Switch off' : 'Switch on'}</button>
    </Box>
  )
}

// Company sign-in (OpenID Connect: Microsoft Entra ID, Google Workspace, Okta ...) - users sign in with their company account (same e-mail)
function Sso() {
  const [d, , err, setErr, setD] = useGet('/settings/sso')
  const [msg, run] = useRun(setErr)
  const [v, setV] = useState(null)
  if (!d) return err ? <div className="alert bad">{err}</div> : null
  const val = v || { enabled: d.enabled, issuer: d.issuer, client_id: d.client_id, client_secret: '', only: d.only }
  const set = (k) => (e) => setV({ ...val, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })
  return (
    <>
      <h3>Company sign-in (single sign-on)</h3>
      <p className="muted small">Users sign in with their company account - Microsoft 365, Google Workspace, Okta or any OpenID Connect service - when the e-mail of their panel user is the same. Give the sign-in service this redirect address: <code style={{ overflowWrap: 'anywhere' }}>{d.redirect_uri}</code></p>
      {err && <div className="alert bad">{err}</div>}
      {msg && <div className="alert ok">{msg}</div>}
      <form onSubmit={(e) => { e.preventDefault(); run(api('/settings/sso', val), 'Saved.', (r) => { setD({ ...d, ...r }); setV(null) }) }} style={{ display: 'grid', gap: 10 }}>
        <label>Issuer address<input value={val.issuer} onChange={set('issuer')} placeholder="https://login.microsoftonline.com/<tenant id>/v2.0" /></label>
        <label>Client ID<input value={val.client_id} onChange={set('client_id')} /></label>
        <label>Client secret<input type="password" value={val.client_secret} onChange={set('client_secret')} placeholder={d.has_secret ? 'Saved - type only to change it' : ''} autoComplete="new-password" /></label>
        <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}><input type="checkbox" checked={val.enabled} onChange={set('enabled')} style={{ width: 'auto' }} /> Switched on</label>
        <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}><input type="checkbox" checked={val.only} onChange={set('only')} style={{ width: 'auto' }} /> Company sign-in only (users cannot use their panel password; the owner always can)</label>
        <button className="btn primary" style={{ justifySelf: 'start' }}>Save</button>
      </form>
    </>
  )
}
