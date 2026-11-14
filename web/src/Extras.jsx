import { ask, show } from './dialog.jsx'
import React, { useEffect, useState } from 'react'
import { api, bytes, upload } from './api.js'

// The rest of Webuzo's admin screens (owner only): mail settings, mail log, FTP sessions, process rules, PHP-FPM / MySQL / Apache,
// users and access, IPs / disks / sites, export and DNS clean-up. Every change is in the activity log.

function useGet(path) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const load = () => api(path).then((x) => { setD(x); setErr('') }).catch((e) => setErr(e.message))
  useEffect(() => { load() }, [path])
  return [d, load, err, setErr]
}

function Box({ err, msg, children }) {
  return <div className="card">{err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}{children}</div>
}

function useRun(load, setErr) {
  const [msg, setMsg] = useState('')
  const run = (p, m) => p.then((r) => { setMsg(m || 'Saved.'); setErr(''); load && load(); return r }).catch((e) => { setMsg(''); setErr(e.message) })
  return [msg, run]
}

export const EXTRA_TABS = [['mailx', 'Mail settings'], ['maillog', 'Mail log'], ['ftpx', 'FTP sessions'], ['procx', 'Process rules'], ['svc', 'PHP-FPM, MySQL, Apache'], ['access', 'Users and access'], ['move', 'IPs, disks and sites'], ['export', 'Export and clean-up'], ['limits', 'Sending limits'], ['plugins', 'Plugins']]

export function ExtraTab({ tab }) {
  return <>{tab === 'mailx' && <MailSettings />}{tab === 'maillog' && <MailLog />}{tab === 'ftpx' && <FtpSessions />}{tab === 'procx' && <ProcessRules />}
    {tab === 'svc' && <ServiceSettings />}{tab === 'access' && <Access />}{tab === 'move' && <Move />}{tab === 'export' && <ExportCleanup />}{tab === 'limits' && <SendingLimits />}{tab === 'plugins' && <PluginsTab />}</>
}

function MailSettings() {
  const [d, load, err, setErr] = useGet('/extras/mail')
  const [msg, run] = useRun(load, setErr)
  const [relay, setRelay] = useState({ host: '', port: 587, user: '', password: '' })
  const [route, setRoute] = useState('')
  const [blocks, setBlocks] = useState(null)
  const [sys, setSys] = useState('')
  const [tls, setTls] = useState('')
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const b = blocks || { senders: d.blocks.senders.join(' '), countries: d.blocks.countries.join(' ') }
  const f = (k) => (e) => setRelay({ ...relay, [k]: e.target.value })
  return (
    <Box err={err} msg={msg}>
      <h3>Send through another mail server (relay)</h3>
      <p className="muted small">{d.relay ? `Now: ${d.relay.host}:${d.relay.port}${d.relay.user ? ' as ' + d.relay.user : ''}` : 'Now: this server sends mail directly.'}</p>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/mail/relay', relay), 'Relay saved.') }}>
        <label style={{ minWidth: 200 }}>Server<input value={relay.host} onChange={f('host')} placeholder="smtp.example.com" required /></label>
        <label style={{ width: 90 }}>Port<input type="number" value={relay.port} onChange={f('port')} /></label>
        <label style={{ minWidth: 160 }}>Login<input value={relay.user} onChange={f('user')} autoComplete="off" /></label>
        <label style={{ minWidth: 160 }}>Password<input type="password" value={relay.password} onChange={f('password')} autoComplete="new-password" /></label>
        <button className="btn primary">Save</button>
        {d.relay && <button type="button" className="btn" onClick={() => run(api('/extras/mail/relay', { host: '' }), 'Mail goes out directly again.')}>Stop using the relay</button>}
      </form>

      <h3>Where a domain's mail is kept</h3>
      <p className="muted small">Remote: the domain's mailboxes are elsewhere (Google Workspace, Zoho ...), so mail from this server goes to them. {d.remote.length ? 'Remote now: ' + d.remote.join(', ') : 'All domains are kept here.'}</p>
      <div className="row" style={{ alignItems: 'flex-end' }}>
        <label style={{ minWidth: 220 }}>Domain<input value={route} onChange={(e) => setRoute(e.target.value)} placeholder="example.com" /></label>
        <button className="btn" onClick={() => run(api('/extras/mail/routing', { domain: route, mode: 'remote' }), 'Mail of ' + route + ' goes to its own MX now.')}>Remote</button>
        <button className="btn" onClick={() => run(api('/extras/mail/routing', { domain: route, mode: 'local' }), 'Mail of ' + route + ' is kept here.')}>Local</button>
      </div>

      <h3>Refuse mail</h3>
      <form onSubmit={(e) => { e.preventDefault(); run(api('/extras/mail/blocks', b), 'Mail blocks saved.') }}>
        <label>From these senders or domains<textarea rows="2" value={b.senders} onChange={(e) => setBlocks({ ...b, senders: e.target.value })} placeholder="spam.example someone@bad.example" /></label>
        <label>From these countries (two-letter codes)<input value={b.countries} onChange={(e) => setBlocks({ ...b, countries: e.target.value })} placeholder="cn ru" /></label>
        <button className="btn primary">Save</button>
      </form>

      <h3>Scripts may not send mail directly</h3>
      <p className="muted small">Only the mail server may talk to other mail servers, so a hacked site cannot send spam around it. Now: {d.smtp_restricted ? 'on' : 'off'}.</p>
      <button className="btn" onClick={() => run(api('/extras/mail/smtp-restrict', { on: !d.smtp_restricted }), 'Saved.')}>{d.smtp_restricted ? 'Switch off' : 'Switch on'}</button>

      <form className="row" style={{ alignItems: 'flex-end', marginTop: 16 }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/mail/system', { email: sys }), 'Server mail goes to ' + sys + '.') }}>
        <label style={{ minWidth: 260 }}>Server's own mail (cron, alerts) goes to<input type="email" value={sys} onChange={(e) => setSys(e.target.value)} placeholder={d.system_mail || 'you@example.com'} required /></label><button className="btn">Save</button>
      </form>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/mail/tls', { domain: tls }), 'Mail services use the certificate of ' + tls + '.') }}>
        <label style={{ minWidth: 260 }}>Certificate for the mail services (site)<input value={tls} onChange={(e) => setTls(e.target.value)} placeholder={d.service_tls || 'mail.example.com'} required /></label><button className="btn">Use it</button>
      </form>
    </Box>
  )
}

function MailLog() {
  const [find, setFind] = useState('')
  const [q, setQ] = useState('')
  const [d, , err] = useGet('/extras/mail/log' + (q ? '?find=' + encodeURIComponent(q) : ''))
  return (
    <Box err={err}>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); setQ(find) }}>
        <label style={{ minWidth: 260 }}>Find an address<input value={find} onChange={(e) => setFind(e.target.value)} placeholder="name@example.com" /></label><button className="btn">Find</button></form>
      {d && <>
        <h3>Senders</h3>
        <div className="wrap"><table className="table"><thead><tr><th>Sender</th><th>Sent</th><th>Waiting</th><th>Bounced</th><th>Gave up</th></tr></thead>
          <tbody>{d.summary.map((s) => <tr key={s.sender}><td>{s.sender}</td><td>{s.sent}</td><td>{s.deferred}</td><td>{s.bounced}</td><td>{s.expired}</td></tr>)}</tbody></table></div>
        <h3>Messages</h3>
        {d.rows.length === 0 ? <p className="muted">Nothing in the mail log.</p> : <div className="wrap"><table className="table"><thead><tr><th>When</th><th>From</th><th>To</th><th>Result</th><th>Answer of the other server</th></tr></thead>
          <tbody>{d.rows.map((r, i) => <tr key={i}><td className="small">{r.time}</td><td>{r.from}</td><td>{r.to}</td><td><span className={'pill ' + (r.status === 'sent' ? 'on' : 'off')}>{r.status}</span></td><td className="small" style={{ wordBreak: 'break-word' }}>{r.detail}</td></tr>)}</tbody></table></div>}
      </>}
    </Box>
  )
}

function FtpSessions() {
  const [d, load, err, setErr] = useGet('/extras/ftp-sessions')
  const [msg, run] = useRun(load, setErr)
  return (
    <Box err={err} msg={msg}>
      <button className="btn sm" onClick={load}>Refresh</button>
      {d && (d.sessions.length === 0 ? <p className="muted">Nobody is connected over FTP.</p> : <div className="wrap"><table className="table"><thead><tr><th>User</th><th>From</th><th>Doing</th><th>File</th><th>Seconds</th><th /></tr></thead>
        <tbody>{d.sessions.map((s) => <tr key={s.pid}><td>{s.user}</td><td>{s.from}</td><td>{s.state}</td><td className="small" style={{ wordBreak: 'break-all' }}>{s.file}</td><td>{s.seconds}</td>
          <td className="right"><button className="btn sm danger" onClick={() => run(api('/extras/ftp-sessions/' + s.pid + '/close', {}), 'Session closed.')}>Close</button></td></tr>)}</tbody></table></div>)}
    </Box>
  )
}

function ProcessRules() {
  const [d, load, err, setErr] = useGet('/extras/processes')
  const [msg, run] = useRun(load, setErr)
  const [names, setNames] = useState(null)
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const n = names ?? d.names.join(' ')
  const last = d.log[0]
  return (
    <Box err={err} msg={msg}>
      <h3>Programs that are always stopped</h3>
      <p className="muted small">Checked every 5 minutes, for example crypto miners or IRC bots found on hacked sites.</p>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/processes/killer', { names: n.split(/[\s,]+/).filter(Boolean) }), 'List saved.') }}>
        <label style={{ flex: 1, minWidth: 240 }}>Program names<input value={n} onChange={(e) => setNames(e.target.value)} placeholder="xmrig minerd" /></label><button className="btn primary">Save</button></form>
      <h3>Use per Linux user (last hour{last ? ', ' + new Date(last.at * 1000).toLocaleString() : ''})</h3>
      {!last ? <p className="muted">The first entry is written within the hour.</p> : <div className="wrap"><table className="table"><thead><tr><th>User</th><th>CPU %</th><th>Memory %</th><th>Programs</th></tr></thead>
        <tbody>{[...last.users].sort((a, b) => b.cpu - a.cpu).map((u) => <tr key={u.user}><td>{u.user}</td><td>{u.cpu}</td><td>{u.mem}</td><td>{u.procs}</td></tr>)}</tbody></table></div>}
    </Box>
  )
}

function KeyValues({ title, path, keys, hint }) {
  const [d, load, err, setErr] = useGet(path)
  const [msg, run] = useRun(load, setErr)
  const [v, setV] = useState({})
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  const val = (k) => v[k] ?? d.settings[k] ?? ''
  return (
    <form onSubmit={(e) => { e.preventDefault(); const s = {}; keys.forEach((k) => { if (val(k) !== '') s[k] = val(k) }); run(api(path, { settings: s }), title + ' saved.') }}>
      <h3>{title}</h3>{hint && <p className="muted small">{hint}</p>}
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}
      <div className="grid2">{keys.map((k) => <label key={k}>{k}<input value={val(k)} onChange={(e) => setV({ ...v, [k]: e.target.value })} /></label>)}</div>
      <button className="btn primary">Save</button>
    </form>
  )
}

function ServiceSettings() {
  const [php, setPhp] = useState(null)
  const [pw, setPw] = useState('')
  const [err, setErr] = useState('')
  const [msg, run] = useRun(null, setErr)
  useEffect(() => { api('/php-versions').then((x) => setPhp(x.versions[x.versions.length - 1])).catch((e) => setErr(e.message)) }, [])
  return (
    <Box err={err} msg={msg}>
      {php && <KeyValues key={php} title={'PHP ' + php + ' (PHP-FPM)'} path={'/extras/php-fpm/' + php} keys={['pm', 'pm.max_children', 'pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers', 'pm.max_requests', 'request_terminate_timeout']} hint="pm: dynamic, static or ondemand." />}
      <KeyValues title="MySQL / MariaDB" path="/extras/mysql" keys={['max_connections', 'innodb_buffer_pool_size', 'max_allowed_packet', 'wait_timeout', 'tmp_table_size', 'max_heap_table_size', 'slow_query_log', 'long_query_time']} hint="Sizes like 512M or 1G. The database server restarts; if it does not start, the old settings come back." />
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/mysql/root-password', { password: pw }).then(() => setPw('')), 'Root password changed.') }}>
        <label style={{ minWidth: 260 }}>New database root password<input type="password" value={pw} onChange={(e) => setPw(e.target.value)} minLength="12" autoComplete="new-password" required /></label><button className="btn">Change</button></form>
      <KeyValues title="Apache (when installed)" path="/extras/apache" keys={['Timeout', 'KeepAlive', 'MaxKeepAliveRequests', 'KeepAliveTimeout', 'ServerTokens', 'ServerSignature']} />
      <TomcatPassword run={run} />
    </Box>
  )
}

function Access() {
  const [d, load, err, setErr] = useGet('/extras/users')
  const [msg, run] = useRun(load, setErr)
  const [who, setWho] = useState('')
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <Box err={err} msg={msg}>
      <h3>Administrators (sudo)</h3>
      <p>{d.members.length ? d.members.join(', ') : <span className="muted">Nobody besides root.</span>}</p>
      <div className="row" style={{ alignItems: 'flex-end' }}>
        <label style={{ minWidth: 200 }}>Linux user<input value={who} onChange={(e) => setWho(e.target.value)} /></label>
        <button className="btn" onClick={() => run(api('/extras/users/sudo', { user: who, add: true }), who + ' is an administrator now.')}>Add</button>
        <button className="btn" onClick={() => run(api('/extras/users/sudo', { user: who, add: false }), who + ' is no administrator any more.')}>Remove</button>
      </div>
      <h3>Shell (SSH) access</h3>
      <div className="wrap"><table className="table"><thead><tr><th>User</th><th>Shell</th><th /></tr></thead>
        <tbody>{d.users.map((u) => <tr key={u.user}><td>{u.user}</td><td className="code small">{u.shell}</td>
          <td className="right"><button className="btn sm" onClick={() => run(api('/extras/users/shell', { user: u.user, on: !u.on }), 'Saved.')}>{u.on ? 'Take away' : 'Allow'}</button></td></tr>)}</tbody></table></div>
      <h3>Compilers</h3>
      <p className="muted small">{d.open ? 'Everyone may run them.' : 'Only root and the "compiler" group.'} {d.programs.join(', ')}</p>
      <button className="btn" onClick={() => run(api('/extras/compilers', { open: !d.open }), 'Saved.')}>{d.open ? 'Only root' : 'Allow everyone'}</button>
      <h3>Package database</h3>
      <p className="muted small">After an install broke half way: finishes waiting packages and repairs missing parts.</p>
      <button className="btn" onClick={() => run(api('/extras/packages/repair', {}), 'Package database checked.')}>Repair</button>
    </Box>
  )
}

function Move() {
  const [ip, loadIp, err, setErr] = useGet('/extras/shared-ip')
  const [st, loadSt] = useGet('/extras/storage')
  const [sites] = useGet('/sites')
  const [users] = useGet('/users')
  const [msg, run] = useRun(() => { loadIp(); loadSt() }, setErr)
  const [mig, setMig] = useState({ old: '', new: '' })
  const [site, setSite] = useState('')
  const [owner, setOwner] = useState('')
  const [to, setTo] = useState('')
  const [bdir, setBdir] = useState('')
  const [extra, setExtra] = useState(null)
  useEffect(() => { setExtra(null); if (site) api('/extras/sites/' + site + '/extra-conf').then((x) => setExtra(x.text)).catch((e) => setErr(e.message)) }, [site])
  return (
    <Box err={err} msg={msg}>
      <h3>Server address</h3>
      {ip && <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/shared-ip', { ip: e.target.ip.value }), 'Shared address saved.') }}>
        <label style={{ minWidth: 220 }}>Address for new sites and zones<select name="ip" defaultValue={ip.ip || ''}>{ip.ips.map((i) => <option key={i.ip}>{i.ip}</option>)}</select></label><button className="btn">Save</button></form>}
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); window.confirm('Change ' + mig.old + ' to ' + mig.new + ' in every DNS zone?') && run(api('/extras/ip-migration', mig).then((r) => r), 'DNS zones moved to the new address.') }}>
        <label style={{ minWidth: 160 }}>Old IP<input value={mig.old} onChange={(e) => setMig({ ...mig, old: e.target.value })} required /></label>
        <label style={{ minWidth: 160 }}>New IP<input value={mig.new} onChange={(e) => setMig({ ...mig, new: e.target.value })} required /></label><button className="btn">Change everywhere</button></form>

      <h3>Disks</h3>
      {st && <><div className="wrap"><table className="table"><thead><tr><th>Mounted at</th><th>Type</th><th>Size</th><th>Free</th></tr></thead>
        <tbody>{st.disks.map((k) => <tr key={k.mount}><td>{k.mount}</td><td>{k.type}</td><td>{bytes(k.size)}</td><td>{bytes(k.free)}</td></tr>)}</tbody></table></div>
        <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/storage', { backup_dir: bdir }), 'Backups go to ' + bdir + ' now.') }}>
          <label style={{ minWidth: 260 }}>Keep backups in<input value={bdir} onChange={(e) => setBdir(e.target.value)} placeholder={st.backup_dir} required /></label><button className="btn">Save</button></form></>}

      <h3>One site</h3>
      <label style={{ maxWidth: 360 }}>Site<select value={site} onChange={(e) => setSite(e.target.value)}><option value="">Choose a site</option>{(sites?.sites || []).map((s) => <option key={s.id} value={s.id}>{s.domain}</option>)}</select></label>
      {site && <>
        <div className="row" style={{ alignItems: 'flex-end' }}>
          <label style={{ minWidth: 200 }}>Give it to<select value={owner} onChange={(e) => setOwner(e.target.value)}><option value="">Choose a user</option>{(users?.users || []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></label>
          <button className="btn" onClick={() => run(api('/extras/sites/' + site + '/owner', { user_id: Number(owner) }), 'Owner changed (with its databases, FTP users, DNS zone and mail).')}>Change owner</button></div>
        <div className="row" style={{ alignItems: 'flex-end' }}>
          <label style={{ minWidth: 260 }}>Move its files to<input value={to} onChange={(e) => setTo(e.target.value)} placeholder="/mnt/disk2/www" /></label>
          <button className="btn" onClick={() => window.confirm('Copy the site to ' + to + ' and switch it over?') && run(api('/extras/sites/' + site + '/move', { to }), 'Moved. The old copy stays until you delete it.')}>Move</button></div>
        {extra !== null && <form onSubmit={(e) => { e.preventDefault(); run(api('/extras/sites/' + site + '/extra-conf', { text: extra }), 'Extra lines saved.') }}>
          <label>Extra nginx lines for this site<textarea rows="5" className="code" value={extra} onChange={(e) => setExtra(e.target.value)} placeholder="client_max_body_size 64m;" /></label>
          <button className="btn primary">Save</button></form>}
      </>}
    </Box>
  )
}

function ExportCleanup() {
  const [dns, loadDns, err, setErr] = useGet('/extras/dns-cleanup')
  const [msg, run] = useRun(loadDns, setErr)
  const [users] = useGet('/users')
  const download = () => api('/extras/export').then((x) => {
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([JSON.stringify(x, null, 2)], { type: 'application/json' }))
    a.download = 'zarspanel-settings.json'
    a.click()
  }).catch((e) => setErr(e.message))
  const importFile = (f) => f && f.text().then((t) => run(api('/extras/import', { data: JSON.parse(t) }), 'Settings and plans imported.')).catch((e) => setErr(e.message))
  return (
    <Box err={err} msg={msg}>
      <h3>Settings to another server</h3>
      <p className="muted small">Panel settings and hosting plans as one file. Passwords, keys and the licence stay on this server.</p>
      <div className="row"><button className="btn" onClick={download}>Download</button>
        <label className="btn">Import a file<input type="file" accept="application/json" hidden onChange={(e) => importFile(e.target.files[0])} /></label></div>
      <h3>DNS zones nobody uses</h3>
      {dns && (dns.zones.length === 0 ? <p className="muted">Every zone belongs to a site or a mail domain.</p> : <>
        <p>{dns.zones.join(', ')}</p>
        <button className="btn danger" onClick={() => window.confirm('Delete these ' + dns.zones.length + ' zones?') && run(api('/extras/dns-cleanup', {}), 'Unused zones deleted.')}>Delete them</button></>)}
      <h3>Monthly traffic from zero</h3>
      <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/bandwidth/' + e.target.u.value + '/reset', {}), 'Traffic of this month counts from now.') }}>
        <label style={{ minWidth: 200 }}>User<select name="u">{(users?.users || []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></label><button className="btn">Reset</button></form>
    </Box>
  )
}

function TomcatPassword({ run }) {
  const [pw, setPw] = useState('')
  return (
    <form className="row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); run(api('/extras/tomcat/password', { password: pw }).then((r) => { setPw(''); return r }), 'Tomcat manager password set (user: admin).') }}>
      <label style={{ minWidth: 260 }}>Tomcat manager password (user admin)<input type="password" value={pw} onChange={(e) => setPw(e.target.value)} minLength="12" autoComplete="new-password" required /></label><button className="btn">Set</button></form>
  )
}

function SendingLimits() {
  const [d, load, err, setErr] = useGet('/extras/mail-limits')
  const [msg, run] = useRun(load, setErr)
  const [v, setV] = useState({})
  if (!d) return err ? <div className="alert bad">{err}</div> : <p className="muted">Loading...</p>
  return (
    <Box err={err} msg={msg}>
      <p className="muted small">Messages a mailbox owner may send in one hour. A reseller's number counts all their clients together. Over the limit, mail waits and goes out later; nothing is lost.</p>
      {!d.on && <div className="alert"><p>Limits are not active on the mail server yet.</p><button className="btn primary" onClick={() => run(api('/extras/mail-limits/on', {}), 'Sending limits are active.')}>Switch on</button></div>}
      <div className="wrap"><table className="table"><thead><tr><th>User</th><th>Kind</th><th>Sent this hour</th><th>Limit an hour</th><th /></tr></thead>
        <tbody>{d.users.filter((u) => u.role !== 'owner').map((u) => <tr key={u.id}><td>{u.name}</td><td>{u.role}</td><td>{u.sent}</td>
          <td><input style={{ width: 110 }} type="number" min="0" value={v[u.id] ?? (u.limit ?? '')} placeholder="no limit" onChange={(e) => setV({ ...v, [u.id]: e.target.value })} /></td>
          <td className="right"><button className="btn sm" onClick={() => run(api('/extras/mail-limits/' + u.id, { per_hour: v[u.id] ?? (u.limit ?? '') }), 'Limit of ' + u.name + ' saved.')}>Save</button></td></tr>)}</tbody></table></div>
    </Box>
  )
}

function PluginsTab() {
  const [d, load, err, setErr] = useGet('/extras/plugins')
  const [msg, run] = useRun(load, setErr)
  const [page, setPage] = useState(null)
  const send = (f) => {
    if (!f) return
    run(upload('/extras/plugins/upload', [f]), 'Installed, switched off. Switch it on when you trust it.')
  }
  return (
    <Box err={err} msg={msg}>
      <p className="muted small">A plugin is a zip with plugin.json and plugin.php. It runs with the rights of the panel, so install only plugins you trust; it starts switched off.</p>
      <label className="btn">Install a plugin (zip)<input type="file" accept=".zip" hidden onChange={(e) => send(e.target.files[0])} /></label>
      {d && (d.plugins.length === 0 ? <p className="muted">No plugins yet.</p> : <div className="wrap"><table className="table"><thead><tr><th>Plugin</th><th>Version</th><th>State</th><th /></tr></thead>
        <tbody>{d.plugins.map((p) => <tr key={p.key}><td><strong>{p.name}</strong><div className="muted small">{p.about}</div>{p.error && <div className="small" style={{ color: 'var(--danger)' }}>Stopped: {p.error}</div>}</td><td>{p.version}</td>
          <td><span className={'pill ' + (p.on ? 'on' : 'off')}>{p.on ? 'on' : 'off'}</span></td>
          <td className="right">{p.on ? <><button className="btn sm" onClick={() => run(api('/extras/plugins/' + p.key + '/off', {}), 'Switched off.')}>Switch off</button>{p.page && <button className="btn sm" onClick={() => setPage(p.key)}>Open</button>}</>
            : <button className="btn sm" onClick={async () => { const c = await ask('Type "' + p.key + '" to switch this plugin on'); if (c) run(api('/extras/plugins/' + p.key + '/on', { confirm: c }), 'Switched on.') }}>Switch on</button>}
            <button className="btn sm danger" onClick={() => window.confirm('Remove ' + p.name + '?') && run(api('/extras/plugins/' + p.key + '/delete', {}), 'Removed.')}>Remove</button></td></tr>)}</tbody></table></div>)}
      {page && <div className="card"><div className="row"><strong>{page}</strong><button className="btn sm" onClick={() => setPage(null)}>Close</button></div>
        <iframe title={page} src={'/api/extras/plugins/' + page + '/page'} style={{ width: '100%', minHeight: 420, border: 0 }} /></div>}
    </Box>
  )
}
