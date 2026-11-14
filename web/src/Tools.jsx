import { ask, show } from './dialog.jsx'
import React, { useEffect, useState } from 'react'
import { api, bytes } from './api.js'
import { EXTRA_TABS, ExtraTab } from './Extras.jsx'
import { PLUS_TABS, PlusTab } from './Plus.jsx'
import { FEATURES } from './features.js'
import { ToolCard } from './Sections.jsx'

function useGet(path) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const load = () => api(path).then((x) => { setD(x); setErr('') }).catch((e) => setErr(e.message))
  useEffect(() => { load() }, [path])
  return [d, load, err, setErr]
}

// Server tools (owner): host name, resolvers, processes, system updates, network checks, PHP extensions, SSH keys, mail queue, running queries, reboot.
export function ServerTools({ tab: start, go }) {
  const tab = start || ''
  const tabs = [['server', 'Server'], ['processes', 'Processes'], ['updates', 'System updates'], ['network', 'Network tools'], ['php', 'PHP extensions'], ['ssh', 'SSH keys'], ['queue', 'Mail queue'], ['queries', 'Running queries'], ['webtools', 'phpMyAdmin, webmail, calendars'], ['templates', 'Templates and logs'], ['ips', 'IP addresses'], ['software', 'Software'], ['modules', 'Perl / PEAR modules'], ['remote', 'Other servers'], ['logins', 'Sign-in log'], ...EXTRA_TABS, ...PLUS_TABS]
  return (
    <>
      {!tab && <><h1>Server tools</h1><p className="muted">Every tool of the server, one page each.</p>
        <div className="tool-grid">{tabs.map(([k, l]) => { const f = FEATURES.find((x) => x.to === 'tools/' + k) || { name: l, text: '', icon: 'server', group: 'server', to: 'tools/' + k }; return <ToolCard key={k} f={{ ...f, name: f.name || l, to: 'tools/' + k }} go={go} /> })}</div></>}
      {tab && <h1>{(FEATURES.find((x) => x.to === 'tools/' + tab) || {}).name || (tabs.find(([k]) => k === tab) || [])[1] || 'Server tools'}</h1>}
      {tab === 'server' && <Info />}
      {tab === 'processes' && <Processes />}
      {tab === 'updates' && <Updates />}
      {tab === 'network' && <Network />}
      {tab === 'php' && <PhpExt />}
      {tab === 'ssh' && <SshKeys />}
      {tab === 'queue' && <Queue />}
      {tab === 'queries' && <Queries />}
      {tab === 'webtools' && <WebTools />}
      {tab === 'templates' && <Templates />}
      {tab === 'ips' && <Ips />}
      {tab === 'software' && <Software />}
      {tab === 'modules' && <PerlPear />}
      {tab === 'remote' && <RemoteServers />}
      {tab === 'logins' && <Logins />}
      <ExtraTab tab={tab} />
      <PlusTab tab={tab} />
    </>
  )
}

function Info() {
  const [d, load, err, setErr] = useGet('/server')
  const [host, setHost] = useState('')
  const [res, setRes] = useState('')
  const [msg, setMsg] = useState('')
  const [zones, setZones] = useState([])
  const [tz, setTz] = useState('')
  useEffect(() => { api('/server/timezones').then((r) => { setZones(r.zones); setTz(r.current) }).catch(() => {}) }, [])
  const [tls, setTls] = useState(null)
  const [tlsMail, setTlsMail] = useState('')
  useEffect(() => { api('/server/service-tls').then(setTls).catch(() => {}) }, [])
  const run = (p, m) => p.then(() => { setMsg(m); setErr(''); load() }).catch((e) => setErr(e.message))
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <div className="card">
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}
      <p><strong>{d.hostname}</strong> <span className="muted small">{d.os}, kernel {d.kernel}</span></p>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/server/hostname', { hostname: host }), 'Host name changed.') }}>
        <label style={{ minWidth: 240 }}>Host name<input value={host} onChange={(e) => setHost(e.target.value)} placeholder={d.hostname} required /></label><button className="btn">Change</button></form>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/server/resolvers', { resolvers: res.split(/[\s,]+/) }), 'Resolvers saved.') }}>
        <label style={{ minWidth: 240 }}>DNS resolvers<input value={res} onChange={(e) => setRes(e.target.value)} placeholder={(d.resolvers || []).join(' ') || '1.1.1.1 8.8.8.8'} required /></label><button className="btn">Save</button></form>
      {zones.length > 0 && <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/server/timezone', { timezone: tz }), 'Time zone changed.') }}>
        <label style={{ minWidth: 240 }}>Time zone<select value={tz} onChange={(e) => setTz(e.target.value)}>{zones.map((z) => <option key={z}>{z}</option>)}</select></label><button className="btn">Save</button></form>}
      {tls && <>
        <h3>Panel and mail certificate</h3>
        <p className="muted small">{tls.self_signed ? 'The panel uses its own (self-signed) certificate - browsers warn about it.' : 'Certificate for ' + tls.subject + ' from ' + (tls.issuer || '?') + ', valid until ' + (tls.expires || '').slice(0, 10) + '.'} A free Let's Encrypt certificate for {tls.hostname} is used by the panel and by mail and renews by itself.</p>
        <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); api('/server/service-tls', { email: tlsMail }).then((r) => { setTls(r); setMsg('Certificate installed.') }).catch((x) => setErr(x.message)) }}>
          <label style={{ minWidth: 240 }}>E-mail for certificate notices<input type="email" value={tlsMail} onChange={(e) => setTlsMail(e.target.value)} required /></label><button className="btn">Get the certificate</button></form>
      </>}
      <AcmeCa />
      <BrotliBox />
      <h3>Restart the server</h3>
      <button className="btn danger" onClick={async () => { const c = await ask('Type REBOOT to restart the server now'); if (c) run(api('/server/reboot', { confirm: c }), 'The server is restarting.') }}>Restart</button>
    </div>
  )
}

function Processes() {
  const [d, load, err, setErr] = useGet('/server/processes')
  return (
    <div className="card wrap">
      {err && <div className="alert bad">{err}</div>}
      <button className="btn sm" onClick={load}>Refresh</button>
      {d && <table className="table"><thead><tr><th>PID</th><th>User</th><th>CPU %</th><th>RAM %</th><th>Running for</th><th>Program</th><th /></tr></thead><tbody>
        {d.processes.map((p) => <tr key={p.pid}><td>{p.pid}</td><td>{p.user}</td><td>{p.cpu}</td><td>{p.mem}</td><td>{p.time}</td><td style={{ wordBreak: 'break-all' }}>{p.command}</td>
          <td className="right"><button className="btn sm danger" onClick={() => window.confirm('Stop ' + p.command + ' (' + p.pid + ')?') && api('/server/processes/' + p.pid + '/kill', { signal: 'TERM' }).then(load).catch((e) => setErr(e.message))}>Stop</button></td></tr>)}
      </tbody></table>}
    </div>
  )
}

function Updates() {
  const [d, load, err, setErr] = useGet('/server/updates')
  const [log, setLog] = useState('')
  const [busy, setBusy] = useState(false)
  const go = (sec) => { setBusy(true); api('/server/updates', { security_only: sec }).then((r) => { setLog(r.log); load() }).catch((e) => setErr(e.message)).finally(() => setBusy(false)) }
  return (
    <div className="card">
      {err && <div className="alert bad">{err}</div>}
      {!d ? <p className="muted">Checking the package lists...</p> : <>
        <p>{d.updates.length} package(s) can be updated, {d.updates.filter((u) => u.security).length} of them security fixes.</p>
        <div className="row"><button className="btn primary" disabled={busy} onClick={() => go(true)}>Install security fixes</button><button className="btn" disabled={busy} onClick={() => window.confirm('Update every package now?') && go(false)}>Update everything</button></div>
        <div className="wrap"><table className="table"><tbody>{d.updates.slice(0, 200).map((u) => <tr key={u.package}><td>{u.package}</td><td className="muted small">{u.old} to {u.new}</td><td>{u.security && <span className="pill off">security</span>}</td></tr>)}</tbody></table></div>
      </>}
      {busy && <p className="muted">Working, this can take a few minutes...</p>}
      {log && <pre className="code" style={{ whiteSpace: 'pre-wrap', maxHeight: 360, overflow: 'auto' }}>{log}</pre>}
    </div>
  )
}

function Network() {
  const [f, setF] = useState({ tool: 'ping', host: '' })
  const [out, setOut] = useState('')
  const [err, setErr] = useState('')
  return (
    <div className="card">
      {err && <div className="alert bad">{err}</div>}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); setOut('Running...'); api('/server/network', f).then((r) => { setOut(r.output); setErr('') }).catch((x) => { setErr(x.message); setOut('') }) }}>
        <label>Tool<select value={f.tool} onChange={(e) => setF({ ...f, tool: e.target.value })}><option value="ping">Ping</option><option value="traceroute">Trace route</option><option value="dig">DNS lookup</option><option value="mx">Mail servers (MX)</option><option value="whois">Whois</option></select></label>
        <label style={{ minWidth: 240 }}>Domain or IP<input value={f.host} onChange={(e) => setF({ ...f, host: e.target.value })} required /></label><button className="btn primary">Run</button></form>
      {out && <pre className="code" style={{ whiteSpace: 'pre-wrap' }}>{out}</pre>}
    </div>
  )
}

// every program a hosting server runs (Webuzo's Apps): installed version, newer one, install / update / remove
function Software() {
  const [d, load, err, setErr] = useGet('/server/software')
  const [busy, setBusy] = useState('')
  const [out, setOut] = useState(null)
  const act = (s, action) => {
    if (action === 'remove' && !window.confirm('Remove ' + s.label + '? Sites that use it stop working.')) return
    setBusy(s.key + action); setOut(null)
    api('/server/software', { key: s.key, action }).then((r) => { setOut({ label: s.label, ...r }); load() }).catch((e) => setErr(e.message)).finally(() => setBusy(''))
  }
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const groups = [...new Set(d.software.map((s) => s.group))]
  return (
    <div className="card">
      {err && <div className="alert bad">{err}</div>}
      <p className="muted small">Nothing is compiled on the server: programs come from the system packages, or with "Newest version" from the program's own official repository. Updates keep your settings.</p>
      {out && <div className={'alert ' + (out.ok ? 'ok' : 'bad')}>{out.label}: {out.ok ? 'done' : 'failed'}<details><summary>Details</summary><pre style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{out.log.map((l) => (l.ok ? 'OK ' : 'FAILED ') + l.cmd + '\n' + l.out).join('\n\n')}</pre></details></div>}
      {groups.map((g) => (
        <div key={g}><h3>{g}</h3>
          {d.software.filter((s) => s.group === g).map((s) => (
            <div key={s.key} className="row" style={{ justifyContent: 'space-between', flexWrap: 'wrap', gap: 8, borderTop: '1px solid var(--line, #e5e7eb)', padding: '8px 0' }}>
              <span style={{ minWidth: 0 }}><strong>{s.label}</strong> <span className="muted small">{s.installed ? 'installed ' + (s.version || s.package) : 'not installed'}{s.update ? ' - newer: ' + s.newest : ''}</span></span>
              <span className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
                {!s.installed && <button className="btn primary" disabled={!!busy} onClick={() => act(s, 'install')}>{busy === s.key + 'install' ? 'Installing...' : 'Install'}</button>}
                {s.update && <button className="btn primary" disabled={!!busy} onClick={() => act(s, 'update')}>{busy === s.key + 'update' ? 'Updating...' : 'Update'}</button>}
                {s.repo && <button className="btn" disabled={!!busy} title="Use the program's own official repository" onClick={() => act(s, 'newest')}>{busy === s.key + 'newest' ? 'Working...' : 'Newest version'}</button>}
                {s.installed && <button className="btn danger" disabled={!!busy} onClick={() => act(s, 'remove')}>Remove</button>}
              </span>
            </div>
          ))}
        </div>
      ))}
    </div>
  )
}

function PhpExt() {
  const [d, load, err, setErr] = useGet('/server/php-extensions')
  const [f, setF] = useState({ version: '', extension: '' })
  const [busy, setBusy] = useState(false)
  const run = (install) => { setBusy(true); api('/server/php-extensions', { ...f, install }).then(() => { load(); setF({ ...f, extension: '' }) }).catch((e) => setErr(e.message)).finally(() => setBusy(false)) }
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const versions = Object.keys(d.extensions)
  return (
    <div className="card">
      {err && <div className="alert bad">{err}</div>}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(true) }}>
        <label>PHP<select value={f.version || versions[versions.length - 1]} onChange={(e) => setF({ ...f, version: e.target.value })}>{versions.map((v) => <option key={v}>{v}</option>)}</select></label>
        <label>Extension<input value={f.extension} onChange={(e) => setF({ ...f, extension: e.target.value, version: f.version || versions[versions.length - 1] })} placeholder="redis, imagick, intl, soap..." required /></label>
        <button className="btn primary" disabled={busy}>Install</button><button type="button" className="btn danger" disabled={busy || !f.extension} onClick={() => window.confirm('Remove ' + f.extension + '?') && run(false)}>Remove</button></form>
      {versions.map((v) => <p key={v}><strong>PHP {v}:</strong> <span className="muted small">{d.extensions[v].join(', ')}</span></p>)}
    </div>
  )
}

function SshKeys() {
  const [d, load, err, setErr] = useGet('/server')
  const [key, setKey] = useState('')
  const [priv, setPriv] = useState('')
  const run = (p) => p.then((r) => { setErr(''); if (r.private_key) setPriv(r.private_key); load() }).catch((e) => setErr(e.message))
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <div className="card">
      {err && <div className="alert bad">{err}</div>}
      {priv && <div className="alert"><p>Save this private key now (it is not shown again), then sign in with it.</p><pre className="code" style={{ whiteSpace: 'pre-wrap' }}>{priv}</pre><button className="btn sm" onClick={() => setPriv('')}>Done</button></div>}
      <div className="wrap"><table className="table"><tbody>{d.keys.map((k) => <tr key={k.n}><td>{k.type}</td><td>{k.comment}</td><td className="code small">{k.fingerprint}</td><td className="right"><button className="btn sm danger" onClick={() => window.confirm('Remove this key?') && run(api('/server/ssh-keys/' + k.n + '/delete', {}))}>Remove</button></td></tr>)}</tbody></table></div>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/server/ssh-keys', { key }).then(() => setKey(''))) }}>
        <label style={{ flex: 1, minWidth: 260 }}>Public key<input value={key} onChange={(e) => setKey(e.target.value)} placeholder="ssh-ed25519 AAAA... me@laptop" required /></label><button className="btn primary">Add key</button>
        <button type="button" className="btn" onClick={() => run(api('/server/ssh-keys', { generate: true, comment: 'zarspanel' }))}>Make a new key pair</button></form>
      <h3>Password sign-in over SSH</h3>
      <p>{d.password_login ? 'Allowed.' : 'Switched off: keys only.'}</p>
      <button className="btn" onClick={() => run(api('/server/ssh-password', { on: !d.password_login }))}>{d.password_login ? 'Switch off (keys only)' : 'Allow passwords again'}</button>
    </div>
  )
}

function Queue() {
  const [d, load, err, setErr] = useGet('/mail-queue')
  const run = (a, id) => api('/mail-queue/' + a, id ? { id } : {}).then(load).catch((e) => setErr(e.message))
  return (
    <div className="card wrap">
      {err && <div className="alert bad">{err}</div>}
      <div className="row"><button className="btn" onClick={() => run('flush')}>Try sending all now</button><button className="btn danger" onClick={() => window.confirm('Delete every waiting message?') && run('delete_all')}>Delete all</button></div>
      {d && (d.queue.length === 0 ? <p className="muted">The queue is empty.</p> : <table className="table"><thead><tr><th>From</th><th>To</th><th>Why waiting</th><th>Since</th><th /></tr></thead><tbody>
        {d.queue.map((m) => <tr key={m.id}><td>{m.from}</td><td>{m.to}</td><td className="small">{m.reason}</td><td className="small">{m.since}</td><td className="right"><button className="btn sm danger" onClick={() => run('delete', m.id)}>Delete</button></td></tr>)}</tbody></table>)}
    </div>
  )
}

function Queries() {
  const [d, load, err, setErr] = useGet('/databases-processes')
  return (
    <div className="card wrap">
      {err && <div className="alert bad">{err}</div>}
      <button className="btn sm" onClick={load}>Refresh</button>
      {d && (d.processes.length === 0 ? <p className="muted">No queries running.</p> : <table className="table"><thead><tr><th>Database</th><th>User</th><th>Seconds</th><th>Query</th><th /></tr></thead><tbody>
        {d.processes.map((p) => <tr key={p.id}><td>{p.db}</td><td>{p.user}</td><td>{p.time}</td><td className="code small" style={{ wordBreak: 'break-all' }}>{p.query}</td><td className="right"><button className="btn sm danger" onClick={() => api('/databases-processes/' + p.id + '/kill', {}).then(load).catch((e) => setErr(e.message))}>Stop</button></td></tr>)}</tbody></table>)}
    </div>
  )
}

function Logins() {
  const [d, , err] = useGet('/login-log')
  return (
    <div className="card wrap">{err && <div className="alert bad">{err}</div>}
      <p><a className="btn" href="/api/audit.csv">Download the full activity log (CSV)</a></p>
      {d && <table className="table"><thead><tr><th>When</th><th>Who</th><th>What</th><th>IP</th></tr></thead><tbody>{d.logins.map((l) => <tr key={l.id}><td className="small">{l.created_at}</td><td>{l.user}</td><td>{l.action === 'login' ? 'Signed in' : l.action === 'login.sso' ? 'Signed in (company account)' : 'Wrong password'}</td><td>{l.ip}</td></tr>)}</tbody></table>}
    </div>
  )
}

const PLAN_FEATURES = [['add_domains', 'Add websites'], ['files', 'File manager'], ['ssl', 'SSL certificates'], ['email', 'Email'], ['dns', 'DNS zones'], ['databases', 'Databases'], ['ftp', 'FTP'], ['cron', 'Cron jobs'],
  ['apps', 'App store'], ['backups', 'Backups'], ['redis', 'Redis'], ['git', 'Git deployment'], ['wordpress', 'WordPress manager'], ['developer', 'Laravel tools and background programs'], ['staging', 'Staging copy'],
  ['migrate', 'Moving sites in'], ['uptime', 'Uptime monitor'], ['statistics', 'Statistics and logs']]

// Hosting plans (owner and resellers): named limits given to clients in one step.
export function Plans() {
  const [d, load, err, setErr] = useGet('/plans')
  const [users, setUsers] = useState([])
  const [f, setF] = useState({ id: null, name: '', sites: '', databases: '', crons: '', backups: '', disk_mb: '', bandwidth_gb: '', off: [] })
  useEffect(() => { api('/users').then((r) => setUsers(r.users)).catch(() => {}) }, [])
  const save = (e) => { e.preventDefault(); const q = { sites: f.sites, databases: f.databases, crons: f.crons, backups: f.backups, disk_mb: f.disk_mb, bandwidth_gb: f.bandwidth_gb, off: f.off }; api('/plans' + (f.id ? '/' + f.id : ''), { name: f.name, quota: q }).then(() => { setF({ id: null, name: '', sites: '', databases: '', crons: '', backups: '', disk_mb: '', bandwidth_gb: '', off: [] }); load() }).catch((x) => setErr(x.message)) }
  return (
    <>
      <h1>Hosting plans</h1>
      {err && <div className="alert bad">{err}</div>}
      <form className="card" onSubmit={save}><h2>{f.id ? 'Change plan' : 'New plan'}</h2><div className="form">
        <label>Name<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></label>
        {['sites', 'databases', 'crons', 'backups', 'disk_mb', 'bandwidth_gb'].map((k) => <label key={k}>{{ sites: 'Websites', databases: 'Databases', crons: 'Scheduled jobs', backups: 'Backups kept', disk_mb: 'Disk (MB)', bandwidth_gb: 'Traffic a month (GB)' }[k]} (empty = no limit)<input type="number" min="0" value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></label>)}
      </div>
      <h3>What clients of this plan may use</h3>
      <div className="feature-picks">{PLAN_FEATURES.map(([k, l]) => <label key={k} className="check"><input type="checkbox" checked={!f.off.includes(k)} onChange={(e) => setF({ ...f, off: e.target.checked ? f.off.filter((x) => x !== k) : [...f.off, k] })} /> {l}</label>)}</div>
      <div className="row"><button className="btn primary">Save plan</button>{f.id && <button type="button" className="btn" onClick={() => setF({ id: null, name: '', sites: '', databases: '', crons: '', backups: '', disk_mb: '', bandwidth_gb: '', off: [] })}>Cancel</button>}</div></form>
      {d && d.plans.length === 0 && <div className="card muted">No plans yet. Make one above, then put clients on it.</div>}
      {d && d.plans.length > 0 && <div className="card wrap"><table className="table"><thead><tr><th>Plan</th><th>Limits</th><th>Users</th><th /></tr></thead><tbody>
        {d.plans.map((p) => <tr key={p.id}><td><strong>{p.name}</strong></td><td className="small">{Object.entries(p.quota).filter(([k]) => k !== 'off').map(([k, v]) => k + ': ' + v).join(', ') || 'no limits'}{p.quota.off && p.quota.off.length > 0 && <div className="muted">Not included: {p.quota.off.map((k) => (PLAN_FEATURES.find(([x]) => x === k) || [k, k])[1]).join(', ')}</div>}</td><td>{p.users}</td>
          <td className="right row"><button className="btn sm" onClick={() => setF({ id: p.id, name: p.name, sites: p.quota.sites ?? '', databases: p.quota.databases ?? '', crons: p.quota.crons ?? '', backups: p.quota.backups ?? '', disk_mb: p.quota.disk_mb ?? '', bandwidth_gb: p.quota.bandwidth_gb ?? '', off: p.quota.off || [] })}>Change</button>
            <button className="btn sm danger" onClick={() => api('/plans/' + p.id + '/delete', {}).then(load).catch((x) => setErr(x.message))}>Delete</button></td></tr>)}
      </tbody></table></div>}
      <MassChanges plans={d ? d.plans : []} />
      {d && d.plans.length > 0 && <div className="card"><h2>Put a user on a plan</h2>
        {users.filter((u) => u.role === 'client').map((u) => <div key={u.id} className="row"><span style={{ minWidth: 160 }}>{u.name}</span>
          <select value={u.plan_id || ''} onChange={(e) => api('/users/' + u.id + '/plan', { plan_id: e.target.value || null }).then(() => api('/users').then((r) => setUsers(r.users))).then(load).catch((x) => setErr(x.message))} aria-label={'Plan of ' + u.name}>
            <option value="">No plan</option>{d.plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>)}
      </div>}
    </>
  )
}

// Site extras: error pages, hotlink protection, protected folders, blocked IPs, folder listing, PHP settings, MIME types, own SSL, disk and traffic.
export function SiteExtras({ s }) {
  const [d, load, err, setErr] = useGet('/sites/' + s.id + '/tools')
  const [x, setX] = useState(null)
  const [p, setP] = useState({ path: '/admin', user: '', password: '' })
  const [msg, setMsg] = useState('')
  const [csr, setCsr] = useState('')
  const [cert, setCert] = useState({ certificate: '', chain: '', key: '' })
  useEffect(() => { if (d) setX({ ...d.tools, deny_ips: (d.tools.deny_ips || []).join(' ') }) }, [d])
  const save = (body, m) => api('/sites/' + s.id + '/tools', body).then(() => { setMsg(m || 'Saved.'); setErr(''); load() }).catch((e) => setErr(e.message))
  if (!d || !x) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const days = Object.entries(d.usage.traffic)
  return (
    <>
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}
      <p>Disk used: <strong>{bytes(d.usage.disk_bytes)}</strong>{days.length > 0 && <> - traffic last {days.length} days: <strong>{bytes(days.reduce((a, [, v]) => a + v.bytes, 0))}</strong>, {days.reduce((a, [, v]) => a + v.requests, 0)} requests</>}</p>
      <h3>Error pages</h3>
      <div className="form">{[404, 403, 500, 503].map((c) => <label key={c}>{c} page<input value={x.error_pages[c] || ''} onChange={(e) => setX({ ...x, error_pages: { ...x.error_pages, [c]: e.target.value } })} placeholder={'/errors/' + c + '.html'} /></label>)}</div>
      <h3>Protection</h3>
      <label className="row"><input type="checkbox" checked={!!x.hotlink.on} onChange={(e) => setX({ ...x, hotlink: { ...x.hotlink, on: e.target.checked } })} /> Stop other sites from showing my pictures and videos (hotlink protection)</label>
      {x.hotlink.on && <label>Also allow these domains<input value={x.hotlink.allow} onChange={(e) => setX({ ...x, hotlink: { ...x.hotlink, allow: e.target.value } })} placeholder="google.com facebook.com" /></label>}
      <label>Blocked IP addresses or ranges<input value={x.deny_ips} onChange={(e) => setX({ ...x, deny_ips: e.target.value })} placeholder="203.0.113.9 10.0.0.0/8" /></label>
      <label className="row"><input type="checkbox" checked={!!x.optimize} onChange={(e) => setX({ ...x, optimize: e.target.checked })} /> Optimize website (compress pages, keep pictures and scripts in the visitor's browser)</label>
      <label>Leech protection: most requests per minute from one visitor to protected folders (0 = off)<input type="number" min="0" value={x.leech || 0} onChange={(e) => setX({ ...x, leech: Number(e.target.value) })} /></label>
      <label className="row"><input type="checkbox" checked={!!x.modsec} onChange={(e) => setX({ ...x, modsec: e.target.checked })} /> Web application firewall (ModSecurity with the OWASP rules)</label>
      <label>Speed limit per visitor (KB/s, 0 = none)<input type="number" min="0" value={x.limit_rate_kb || 0} onChange={(e) => setX({ ...x, limit_rate_kb: Number(e.target.value) })} /></label>
      <label className="row"><input type="checkbox" checked={!!x.autoindex} onChange={(e) => setX({ ...x, autoindex: e.target.checked })} /> Show a file list in folders without an index page</label>
      {s.type === 'php' && <><h3>PHP settings</h3><div className="form">{d.php_keys.map((k) => <label key={k}>{k}<input value={x.php[k] || ''} onChange={(e) => setX({ ...x, php: { ...x.php, [k]: e.target.value } })} /></label>)}</div></>}
      <h3>Extra file types (MIME)</h3>
      <textarea rows={3} value={x.mime} onChange={(e) => setX({ ...x, mime: e.target.value })} placeholder="application/manifest+json webmanifest" style={{ width: '100%' }} />
      <div className="row"><button className="btn primary" onClick={() => save({ error_pages: x.error_pages, hotlink: x.hotlink, deny_ips: x.deny_ips, autoindex: x.autoindex, php: x.php, mime: x.mime, modsec: x.modsec, limit_rate_kb: x.limit_rate_kb, optimize: x.optimize, leech: x.leech })}>Save</button></div>

      <h3>Visitor statistics</h3>
      <a className="btn sm" href={'/api/sites/' + s.id + '/stats'} target="_blank" rel="noopener">Open the statistics report</a>
      <GitDeploy s={s} />
      <AppRunner s={s} />
      <h3>Password-protected folders</h3>
      {d.tools.protect.map((f) => <div key={f.path} className="row"><strong>{f.path}</strong><span className="muted small">{f.users.join(', ')}</span>
        {f.users.map((u) => <button key={u} className="btn sm" onClick={() => save({ protect: { path: f.path, remove_user: u } }, 'User removed.')}>Remove {u}</button>)}
        <button className="btn sm danger" onClick={() => save({ protect: { path: f.path, remove: true } }, 'Protection removed.')}>Unprotect</button></div>)}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); save({ protect: p }, 'Folder protected.').then(() => setP({ ...p, user: '', password: '' })) }}>
        <label>Folder<input value={p.path} onChange={(e) => setP({ ...p, path: e.target.value })} required /></label>
        <label>User<input value={p.user} onChange={(e) => setP({ ...p, user: e.target.value })} required /></label>
        <label>Password<input type="password" value={p.password} onChange={(e) => setP({ ...p, password: e.target.value })} minLength={8} required /></label>
        <button className="btn">Add</button></form>

      <h3>Own SSL certificate</h3>
      <p className="muted small">Bought a certificate? Make a request (CSR) here, send it to the certificate authority, then paste the certificate they send back.</p>
      <button className="btn sm" onClick={() => api('/sites/' + s.id + '/ssl/csr', {}).then((r) => setCsr(r.csr)).catch((e) => setErr(e.message))}>Make a request (CSR)</button>
      {csr && <pre className="code" style={{ whiteSpace: 'pre-wrap' }}>{csr}</pre>}
      <form onSubmit={(e) => { e.preventDefault(); api('/sites/' + s.id + '/ssl/install', cert).then((r) => { setMsg('Certificate installed, valid until ' + r.valid_until + '.'); setCert({ certificate: '', chain: '', key: '' }) }).catch((x2) => setErr(x2.message)) }}>
        <label>Certificate<textarea rows={4} value={cert.certificate} onChange={(e) => setCert({ ...cert, certificate: e.target.value })} required style={{ width: '100%' }} /></label>
        <label>Chain / CA bundle (optional)<textarea rows={3} value={cert.chain} onChange={(e) => setCert({ ...cert, chain: e.target.value })} style={{ width: '100%' }} /></label>
        <label>Private key (empty = the one made with the request)<textarea rows={3} value={cert.key} onChange={(e) => setCert({ ...cert, key: e.target.value })} style={{ width: '100%' }} /></label>
        <button className="btn primary">Install certificate</button></form>
    </>
  )
}

function Templates() {
  const [d, load, err, setErr] = useGet('/hosting')
  const [t, setT] = useState(null)
  const [lr, setLr] = useState(null)
  const [msg, setMsg] = useState('')
  useEffect(() => { if (d) { setT(d.dns_template); setLr(d.logrotate) } }, [d])
  const run = (p, m) => p.then(() => { setMsg(m); setErr(''); load() }).catch((e) => setErr(e.message))
  if (!d || t === null) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <div className="card">
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}
      <h3>DNS template for new zones</h3>
      <p className="muted small">One record per line: TYPE NAME VALUE. {'{domain}'} and {'{ip}'} are filled in.</p>
      <textarea rows={8} value={t} onChange={(e) => setT(e.target.value)} style={{ width: '100%' }} className="code" />
      <button className="btn primary" onClick={() => run(api('/hosting/dns-template', { template: t }), 'Template saved.')}>Save template</button>
      <h3>Skeleton folder</h3>
      <p className="muted small">Files in <span className="code">{d.skeleton}</span> are copied into every new website (error pages, robots.txt, a starter page).</p>
      <h3>Log rotation</h3>
      <div className="row" style={{ alignItems: 'flex-end' }}>
        <label>Keep days<input type="number" min="1" value={lr.days} onChange={(e) => setLr({ ...lr, days: e.target.value })} /></label>
        <label>Rotate at MB<input type="number" min="1" value={lr.max_mb} onChange={(e) => setLr({ ...lr, max_mb: e.target.value })} /></label>
        <label className="row"><input type="checkbox" checked={!!lr.compress} onChange={(e) => setLr({ ...lr, compress: e.target.checked })} /> Compress</label>
        <button className="btn" onClick={() => run(api('/hosting/logrotate', lr), 'Log rotation saved.')}>Save</button></div>
      <h3>Greylisting (spam protection for incoming mail)</h3>
      <p>{d.greylisting ? 'On: unknown senders are asked to try again a few minutes later.' : 'Off.'}</p>
      <button className="btn" onClick={() => run(api('/hosting/greylisting', { on: !d.greylisting }), 'Saved.')}>{d.greylisting ? 'Switch off' : 'Switch on'}</button>
    </div>
  )
}

// Brotli compression for every website (smaller pages than gzip; browsers pick it themselves)
function BrotliBox() {
  const [b, setB] = useState(null)
  const [err, setErr] = useState('')
  useEffect(() => { api('/server/brotli').then(setB).catch(() => {}) }, [])
  if (!b) return null
  return (
    <>
      <h3>Brotli compression</h3>
      {err && <div className="alert bad">{err}</div>}
      <p className="muted small">Smaller pages for every website (next to gzip). {b.on ? 'On.' : 'Off.'}</p>
      <button className="btn" onClick={() => { setErr(''); api('/server/brotli', { on: !b.on }).then(setB).catch((x) => setErr(x.message)) }}>{b.on ? 'Switch off' : 'Switch on'}</button>
    </>
  )
}

// which free certificate authority the panel uses: Let's Encrypt, or ZeroSSL with its EAB keys
function AcmeCa() {
  const [c, setC] = useState(null)
  const [f, setF] = useState({ kid: '', hmac: '' })
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  useEffect(() => { api('/server/acme').then((x) => { setC(x); setF({ kid: '', hmac: '' }) }).catch(() => {}) }, [])
  if (!c) return null
  const save = (e) => { e.preventDefault(); setErr(''); api('/server/acme', { ca: c.ca, ...f }).then((x) => { setC(x); setMsg('Saved. New certificates come from ' + (x.ca === 'zerossl' ? 'ZeroSSL' : "Let's Encrypt") + '.') }).catch((x) => setErr(x.message)) }
  return (
    <form onSubmit={save}>
      <h3>Certificate authority</h3>
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}
      <p className="muted small">Free certificates for websites and the panel come from Let's Encrypt, or from ZeroSSL (ZeroSSL dashboard &gt; Developer &gt; EAB credentials).</p>
      <div className="row" style={{ alignItems: 'flex-end' }}>
        <label>Authority<select value={c.ca} onChange={(e) => setC({ ...c, ca: e.target.value })}><option value="letsencrypt">Let's Encrypt</option><option value="zerossl">ZeroSSL</option></select></label>
        {c.ca === 'zerossl' && <><label>EAB key id<input value={f.kid} onChange={(e) => setF({ ...f, kid: e.target.value })} placeholder={c.eab_saved ? 'Saved - leave blank to keep' : ''} /></label>
          <label>EAB HMAC key<input type="password" autoComplete="new-password" value={f.hmac} onChange={(e) => setF({ ...f, hmac: e.target.value })} placeholder={c.eab_saved ? 'Saved - leave blank to keep' : ''} /></label></>}
        <button className="btn">Save</button></div>
    </form>
  )
}

function Ips() {
  const [d, load, err, setErr] = useGet('/ips')
  const [f, setF] = useState({ interface: 'eth0', ip: '' })
  const run = (body) => api('/ips', body).then(() => { setErr(''); load() }).catch((e) => setErr(e.message))
  return (
    <div className="card wrap">
      {err && <div className="alert bad">{err}</div>}
      {d && <table className="table"><thead><tr><th>Interface</th><th>Address</th><th>Type</th><th /></tr></thead><tbody>
        {d.addresses.map((a) => <tr key={a.interface + a.ip}><td>{a.interface}</td><td>{a.ip}/{a.prefix}</td><td>{a.family}</td>
          <td className="right">{d.extra.some((e) => e.ip === a.ip + '/' + a.prefix) && <button className="btn sm danger" onClick={() => window.confirm('Remove ' + a.ip + '?') && run({ action: 'remove', interface: a.interface, ip: a.ip + '/' + a.prefix })}>Remove</button>}</td></tr>)}
      </tbody></table>}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run({ action: 'add', ...f }) }}>
        <label>Interface<input value={f.interface} onChange={(e) => setF({ ...f, interface: e.target.value })} required /></label>
        <label style={{ minWidth: 220 }}>Address with prefix<input value={f.ip} onChange={(e) => setF({ ...f, ip: e.target.value })} placeholder="203.0.113.10/32 or 2001:db8::10/64" required /></label>
        <button className="btn primary">Add address</button></form>
      <IpMove />
    </div>
  )
}

// the server got a new address: DNS zones follow; and the reverse DNS check for mail
function IpMove() {
  const [f, setF] = useState({ old: '', new: '' })
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [ptr, setPtr] = useState(null)
  useEffect(() => { api('/server/ptr').then(setPtr).catch(() => {}) }, [])
  const go = (e) => {
    e.preventDefault(); setErr('')
    if (!window.confirm('Change ' + f.old + ' to ' + f.new + ' in every DNS zone of this server?')) return
    api('/server/ip-change', f).then((r) => setMsg(r.records + ' records in ' + r.zones + ' zones changed.')).catch((x) => setErr(x.message))
  }
  return (
    <>
      <h3>Moved to a new address</h3>
      <p className="muted small">After the server got a new IP address: every DNS zone here gets the new address (A / AAAA records and SPF). Websites already answer on every address.</p>
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={go}>
        <label>Old address<input value={f.old} onChange={(e) => setF({ ...f, old: e.target.value })} required placeholder="203.0.113.10" /></label>
        <label>New address<input value={f.new} onChange={(e) => setF({ ...f, new: e.target.value })} required placeholder="198.51.100.20" /></label>
        <button className="btn">Change in all zones</button></form>
      {ptr && <><h3>Reverse DNS (PTR)</h3><p className={ptr.ok ? 'muted small' : 'alert'}>{ptr.ip ? ptr.ip + ': ' + (ptr.ptr || 'no reverse DNS') + ' - ' : ''}{ptr.hint}</p></>}
    </>
  )
}

function PerlPear() {
  const [d, load, err, setErr] = useGet('/software')
  const [mod, setMod] = useState({ kind: 'perl', name: '' })
  const [log, setLog] = useState('')
  const [busy, setBusy] = useState(false)
  const run = (body) => { setBusy(true); api('/software', body).then((r) => { setLog(r.log); setErr(''); load() }).catch((e) => setErr(e.message)).finally(() => setBusy(false)) }
  return (
    <div className="card wrap">
      {err && <div className="alert bad">{err}</div>}{busy && <p className="muted">Working, this can take a few minutes...</p>}
      {d && <table className="table"><tbody>{d.software.map((x) => <tr key={x.package}><td><strong>{x.name}</strong><div className="muted small">{x.package}{x.version ? ' ' + x.version : ''}</div></td>
        <td className="right">{x.installed ? <button className="btn sm danger" disabled={busy} onClick={() => window.confirm('Remove ' + x.name + '?') && run({ kind: 'package', name: x.package, install: false })}>Remove</button> : <button className="btn sm primary" disabled={busy} onClick={() => run({ kind: 'package', name: x.package, install: true })}>Install</button>}</td></tr>)}</tbody></table>}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run({ ...mod, install: true }) }}>
        <label>Module type<select value={mod.kind} onChange={(e) => setMod({ ...mod, kind: e.target.value })}><option value="perl">Perl (CPAN)</option><option value="pear">PHP PEAR</option></select></label>
        <label style={{ minWidth: 220 }}>Module name<input value={mod.name} onChange={(e) => setMod({ ...mod, name: e.target.value })} placeholder="DBI or Mail_Mime" required /></label>
        <button className="btn" disabled={busy}>Install module</button></form>
      {log && <pre className="code" style={{ whiteSpace: 'pre-wrap', maxHeight: 300, overflow: 'auto' }}>{log}</pre>}
    </div>
  )
}

function RemoteServers() {
  const [d, load, err, setErr] = useGet('/remote')
  const [f, setF] = useState({ name: '', url: 'https://', token: '' })
  return (
    <div className="card wrap">
      {err && <div className="alert bad">{err}</div>}
      {d && (d.servers.length === 0 ? <p className="muted">No other servers yet.</p> : <table className="table"><thead><tr><th>Server</th><th>CPU</th><th>Memory</th><th>Disk</th><th>Version</th><th /></tr></thead><tbody>
        {d.servers.map((x) => <tr key={x.id}><td><a href={x.url} target="_blank" rel="noopener">{x.name}</a></td>{x.ok ? <><td>{x.cpu}%</td><td>{x.memory}%</td><td>{x.disk}%</td><td>{x.version}</td></> : <td colSpan={4}><span className="pill off">not reachable</span></td>}
          <td className="right"><button className="btn sm danger" onClick={() => api('/remote/' + x.id + '/delete', {}).then(load)}>Remove</button></td></tr>)}</tbody></table>)}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); api('/remote', f).then(() => { setF({ name: '', url: 'https://', token: '' }); load() }).catch((x) => setErr(x.message)) }}>
        <label>Name<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></label>
        <label style={{ minWidth: 220 }}>Panel address<input value={f.url} onChange={(e) => setF({ ...f, url: e.target.value })} required /></label>
        <label style={{ minWidth: 220 }}>API token made on that panel<input value={f.token} onChange={(e) => setF({ ...f, token: e.target.value })} placeholder="zp_..." required /></label>
        <button className="btn primary">Add server</button></form>
    </div>
  )
}

// Support tickets: clients write to their reseller, resellers to the owner.
export function Tickets({ me }) {
  const [d, load, err, setErr] = useGet('/tickets')
  const [open, setOpen] = useState(null)
  const [f, setF] = useState({ subject: '', body: '' })
  const [reply, setReply] = useState('')
  const show = (id) => api('/tickets/' + id).then((r) => setOpen(r.ticket)).catch((e) => setErr(e.message))
  return (
    <>
      <h1>Support tickets</h1>
      {err && <div className="alert bad">{err}</div>}
      {me.user.role !== 'owner' && <form className="card" onSubmit={(e) => { e.preventDefault(); api('/tickets', f).then(() => { setF({ subject: '', body: '' }); load() }).catch((x) => setErr(x.message)) }}>
        <h2>Ask for help</h2><label>Subject<input value={f.subject} onChange={(e) => setF({ ...f, subject: e.target.value })} required /></label>
        <label>Message<textarea rows={4} value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} required style={{ width: '100%' }} /></label><button className="btn primary">Send</button></form>}
      {d && <div className="card wrap">{d.tickets.length === 0 ? <p className="muted">No tickets.</p> : <table className="table"><tbody>{d.tickets.map((t) => <tr key={t.id}><td>#{t.id} <strong>{t.subject}</strong><div className="muted small">{t.from_name}, {t.updated_at}</div></td><td><span className={'pill ' + (t.status === 'closed' ? 'off' : 'on')}>{t.status}</span></td><td className="right"><button className="btn sm" onClick={() => show(t.id)}>Open</button></td></tr>)}</tbody></table>}</div>}
      {open && <div className="card"><h2>#{open.id} {open.subject}</h2>
        {open.replies.map((r) => <div key={r.id} className="card" style={{ margin: '8px 0' }}><div className="muted small">{r.name}, {r.created_at}</div><div style={{ whiteSpace: 'pre-wrap' }}>{r.body}</div></div>)}
        <textarea rows={3} value={reply} onChange={(e) => setReply(e.target.value)} style={{ width: '100%' }} aria-label="Reply" />
        <div className="row"><button className="btn primary" onClick={() => api('/tickets/' + open.id, { body: reply }).then((r) => { setOpen(r.ticket); setReply(''); load() })}>Reply</button>
          <button className="btn" onClick={() => api('/tickets/' + open.id, { body: reply, status: 'closed' }).then((r) => { setOpen(r.ticket); setReply(''); load() })}>Reply and close</button>
          <button className="btn" onClick={() => setOpen(null)}>Close view</button></div></div>}
    </>
  )
}

// Move accounts in from cPanel.
export function ImportCpanel() {
  const [d, load, err, setErr] = useGet('/import')
  const [rep, setRep] = useState(null)
  const [busy, setBusy] = useState(false)
  const go = (file) => { setBusy(true); api('/import', { file }).then((r) => { setRep(r); setErr(''); load() }).catch((e) => setErr(e.message)).finally(() => setBusy(false)) }
  return (
    <>
      <h1>Move accounts from cPanel, Webuzo, DirectAdmin or Plesk</h1>
      {err && <div className="alert bad">{err}</div>}
      <div className="card"><p>Make a full backup in cPanel (Backup > Download a full account backup) in Webuzo (Backups > Full backup), in DirectAdmin (Create/Restore Backups, .tar.gz or .tar.zst) or in Plesk (Backup Manager > Back Up, then download it as .tar), copy the file into <span className="code">{d ? d.folder : '...'}</span> with SFTP, then press Import. Websites, addon domains, databases and mailboxes (with their old passwords and mail) come over.</p>
        {d && (d.archives.length === 0 ? <p className="muted">No backup files in the folder yet.</p> : d.archives.map((a) => <div key={a.file} className="row"><span>{a.file} <span className="muted small">{bytes(a.size)}</span></span><button className="btn primary" disabled={busy} onClick={() => go(a.file)}>{busy ? 'Importing...' : 'Import'}</button></div>))}</div>
      {rep && <div className="card"><h2>Imported into user {rep.user}</h2>
        {rep.password && <p>Sign-in password for {rep.user}: <span className="code">{rep.password}</span> (shown once)</p>}
        <p>Websites: {rep.sites.join(', ') || 'none'}</p><p>Mailboxes: {rep.mailboxes.join(', ') || 'none'}</p>
        {rep.certificates && rep.certificates.length > 0 && <p>Own SSL certificates kept: {rep.certificates.join(', ')}</p>}
        {rep.cron && rep.cron.length > 0 && <div className="alert"><p>Scheduled jobs of the old server (add the ones still needed under Scheduled jobs):</p><pre className="code" style={{ whiteSpace: 'pre-wrap' }}>{rep.cron.join('\n')}</pre></div>}
        {rep.databases.map((x) => <p key={x.name}>Database {x.name}: password <span className="code">{x.password}</span>{x.imported ? '' : ' (data import had problems)'}</p>)}
        {rep.skipped.length > 0 && <div className="alert">Skipped: {rep.skipped.join('; ')}</div>}
        <p className="muted small">Update the database passwords in the sites' configuration files (wp-config.php, .env), then point the domains' DNS here.</p></div>}
    </>
  )
}

export function MassChanges({ plans }) {
  const [users, setUsers] = useState([])
  const [sel, setSel] = useState([])
  const [f, setF] = useState({ action: 'suspend', plan_id: '', php: '' })
  const [msg, setMsg] = useState('')
  const load = () => api('/users').then((r) => setUsers(r.users.filter((u) => u.role === 'client')))
  useEffect(() => { load() }, [])
  if (!users.length) return null
  return (
    <div className="card"><h2>Change many users at once</h2>{msg && <div className="alert">{msg}</div>}
      <div className="row">{users.map((u) => <label key={u.id} className="row"><input type="checkbox" checked={sel.includes(u.id)} onChange={() => setSel(sel.includes(u.id) ? sel.filter((x) => x !== u.id) : [...sel, u.id])} />{u.name}{!u.active && ' (suspended)'}</label>)}</div>
      <div className="row" style={{ alignItems: 'flex-end' }}>
        <label>Do<select value={f.action} onChange={(e) => setF({ ...f, action: e.target.value })}><option value="suspend">Suspend (sites off, cannot sign in)</option><option value="unsuspend">Start again</option><option value="plan">Put on a plan</option><option value="php">Change PHP version of their sites</option></select></label>
        {f.action === 'plan' && <label>Plan<select value={f.plan_id} onChange={(e) => setF({ ...f, plan_id: e.target.value })}><option value="">No plan</option>{(plans || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>}
        {f.action === 'php' && <label>PHP<input value={f.php} onChange={(e) => setF({ ...f, php: e.target.value })} placeholder="8.3" /></label>}
        <button className="btn primary" disabled={!sel.length} onClick={() => window.confirm('Apply to ' + sel.length + ' user(s)?') && api('/users-mass', { users: sel, ...f }).then((r) => { setMsg('Changed: ' + r.changed.join(', ')); setSel([]); load() }).catch((e) => setMsg(e.message))}>Apply</button></div>
    </div>
  )
}

function WebTools() {
  const [d, load, err, setErr] = useGet('/tools-web')
  const [busy, setBusy] = useState('')
  const names = { phpmyadmin: 'phpMyAdmin (databases)', webmail: 'Webmail (Roundcube)', calendar: 'Calendars and contacts (CalDAV / CardDAV)', adminer: 'Adminer (PostgreSQL and MySQL in the browser)' }
  return (
    <div className="card">
      {err && <div className="alert bad">{err}</div>}
      {d && Object.entries(d.tools).map(([k, t]) => <div key={k} className="row"><strong style={{ minWidth: 240 }}>{names[k]}</strong>
        {t.installed ? <a className="btn sm" href={t.url} target="_blank" rel="noopener">Open</a> : <button className="btn sm primary" disabled={!!busy} onClick={() => { setBusy(k); api('/tools-web/' + k, {}).then(load).catch((e) => setErr(e.message)).finally(() => setBusy('')) }}>{busy === k ? 'Installing...' : 'Install'}</button>}</div>)}
      <p className="muted small">Mailboxes sign in to webmail and calendars with their own address and password. Database users open phpMyAdmin from the Databases page.</p>
    </div>
  )
}

function GitDeploy({ s }) {
  const [f, setF] = useState({ repo: '', branch: 'main', composer: false, npm: false })
  const [r, setR] = useState(null)
  const [err, setErr] = useState('')
  return (
    <>
      <h3>Deploy from Git</h3>
      {err && <div className="alert bad">{err}</div>}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); setErr(''); api('/sites/' + s.id + '/git', f).then(setR).catch((x) => setErr(x.message)) }}>
        <label style={{ minWidth: 260 }}>Repository<input value={f.repo} onChange={(e) => setF({ ...f, repo: e.target.value })} placeholder="https://github.com/you/site.git" required /></label>
        <label>Branch<input value={f.branch} onChange={(e) => setF({ ...f, branch: e.target.value })} required /></label>
        <label className="row"><input type="checkbox" checked={f.composer} onChange={(e) => setF({ ...f, composer: e.target.checked })} /> composer install</label>
        <label className="row"><input type="checkbox" checked={f.npm} onChange={(e) => setF({ ...f, npm: e.target.checked })} /> npm build</label>
        <button className="btn">Deploy now</button></form>
      {r && <><p>{r.ok ? 'Deployed.' : 'Git reported a problem.'} Webhook for GitHub / GitLab (deploys on every push): <span className="code" style={{ wordBreak: 'break-all' }}>{window.location.origin + r.hook}</span></p><pre className="code" style={{ whiteSpace: 'pre-wrap', maxHeight: 240, overflow: 'auto' }}>{r.log}</pre></>}
    </>
  )
}

function AppRunner({ s }) {
  const [f, setF] = useState({ kind: 'node', port: 3000, start: 'npm start' })
  const [msg, setMsg] = useState('')
  const [log, setLog] = useState('')
  const run = (p) => p.then((r) => { setMsg('App: ' + (r.state || 'done')); if (r.log) setLog(r.log) }).catch((e) => setMsg(e.message))
  return (
    <>
      <h3>Node.js or Python app</h3>
      <p className="muted small">The app is kept running and restarted if it stops; the website passes visitors to its port.</p>
      {msg && <div className="alert">{msg}</div>}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/sites/' + s.id + '/app', { ...f, port: Number(f.port) })) }}>
        <label>Type<select value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value, start: e.target.value === 'node' ? 'npm start' : 'gunicorn -b 127.0.0.1:' + f.port + ' app:app' })}><option value="node">Node.js</option><option value="python">Python</option></select></label>
        <label>Port<input type="number" value={f.port} onChange={(e) => setF({ ...f, port: e.target.value })} /></label>
        <label style={{ minWidth: 220 }}>Start command<input value={f.start} onChange={(e) => setF({ ...f, start: e.target.value })} /></label>
        <button className="btn">Run the app</button></form>
      <div className="row">{['restart', 'stop', 'start', 'logs'].map((a) => <button key={a} className="btn sm" onClick={() => run(api('/sites/' + s.id + '/app/' + a, {}))}>{a}</button>)}</div>
      {log && <pre className="code" style={{ whiteSpace: 'pre-wrap', maxHeight: 240, overflow: 'auto' }}>{log}</pre>}
    </>
  )
}
