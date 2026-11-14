import { ask, show } from './dialog.jsx'
import React, { useEffect, useState } from 'react'
import { api } from './api.js'

function Secret({ text, onClose }) {
  return (
    <div className="modal" role="dialog" aria-modal="true"><div className="card">
      <h2>{text.title}</h2><p>Copy this now. It is not shown again.</p>
      <p className="code" style={{ fontSize: 16, padding: 10 }}>{text.value}</p>
      <div className="row"><button className="btn" onClick={() => navigator.clipboard && navigator.clipboard.writeText(text.value)}>Copy</button><button className="btn primary" onClick={onClose}>Done</button></div>
    </div></div>
  )
}

function useData(path) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const load = () => api(path).then(setD).catch((e) => setErr(e.message))
  useEffect(() => { load() }, [])
  return [d, load, err, setErr]
}

// DNSSEC of one zone: switch on, then give the DS record to the domain registrar
function Dnssec({ z }) {
  const [st, setSt] = useState(null)
  const [show, setShow] = useState(false)
  const [err, setErr] = useState('')
  const get = () => api('/dns/' + z.id + '/dnssec').then(setSt).catch((e) => setErr(e.message))
  const flip = () => api('/dns/' + z.id + '/dnssec', { on: !st.dnssec }).then(setSt).catch((e) => setErr(e.message))
  return (
    <>
      <button className="btn sm" onClick={() => { setShow(!show); if (!st) get() }}>DNSSEC</button>
      {show && <div style={{ flexBasis: '100%' }}>
        {err && <div className="alert bad">{err}</div>}
        {!st ? <p className="muted small">Loading...</p> : <>
          <p className="muted small">{st.dnssec ? 'Signed. Give this DS record to your domain registrar (it can take a minute to appear after switching on):' : 'DNSSEC signs the zone so nobody can fake its answers. After switching on, add the DS record at your registrar.'}</p>
          {st.dnssec && (st.ds.length ? st.ds.map((x) => <p key={x} className="code" style={{ overflowWrap: 'anywhere' }}>{x}</p>) : <p className="muted small">No key yet - <button className="btn sm" onClick={get}>Check again</button></p>)}
          <button className={'btn sm' + (st.dnssec ? ' danger' : ' primary')} onClick={() => (!st.dnssec || window.confirm('Remove the DS record at the registrar FIRST, or the domain stops working. Switch off?')) && flip()}>{st.dnssec ? 'Switch off' : 'Switch on'}</button>
        </>}
      </div>}
    </>
  )
}

export function Dns() {
  const [d, load, err, setErr] = useData('/dns')
  const [f, setF] = useState({ domain: '', ip: '' })
  const [open, setOpen] = useState(null)
  const [r, setR] = useState({ type: 'A', name: '@', value: '', ttl: 3600, prio: '' })
  const run = (p) => p.then(() => { setErr(''); load() }).catch((e) => setErr(e.message))
  return (
    <>
      <h1>DNS zones</h1>
      {err && <div className="alert bad">{err}</div>}
      {d && d.zones && d.zones.length > 0 && <form className="card row" style={{ alignItems: 'flex-end' }} onSubmit={(e) => { e.preventDefault(); const t = e.target.elements.ttl.value; window.confirm('Set the TTL of every record of all ' + d.zones.length + ' zones to ' + t + ' seconds?') && run(api('/dns-ttl', { ttl: Number(t) })) }}>
        <label style={{ width: 220 }}>One TTL for all zones (seconds)<input name="ttl" type="number" min="60" max="604800" defaultValue="3600" /></label><button className="btn">Set</button></form>}
      <form className="card row" onSubmit={(e) => { e.preventDefault(); run(api('/dns', f).then(() => setF({ domain: '', ip: '' }))) }}>
        <label style={{ minWidth: 240 }}>Domain<input value={f.domain} onChange={(e) => setF({ ...f, domain: e.target.value })} placeholder="example.com" required /></label>
        <label>Server IP (makes the first records)<input value={f.ip} onChange={(e) => setF({ ...f, ip: e.target.value })} placeholder="203.0.113.7" /></label>
        <button className="btn primary">Add zone</button>
      </form>
      {!d ? <p className="muted">Loading...</p> : d.zones.length === 0 ? <div className="card muted">No zones yet.</div> : d.zones.map((z) => (
        <div key={z.id} className="card">
          <div className="row"><strong>{z.domain}</strong><span className="muted small">{z.records.length} records</span>
            <button className="btn sm" onClick={() => setOpen(open === z.id ? null : z.id)}>{open === z.id ? 'Close' : 'Edit records'}</button>
            <button className="btn sm" onClick={async () => { const n = await ask('Dynamic DNS: name of the record that follows a changing IP (office makes office.' + z.domain + ')'); if (n) api('/dns/' + z.id + '/ddns', { name: n }).then((r) => alert('Update address for ' + r.host + ' (shown once):\n' + r.url)).catch((e) => setErr(e.message)) }}>Dynamic DNS</button>
            <Dnssec z={z} />
            <button className="btn sm danger" onClick={() => window.confirm('Delete the zone ' + z.domain + ' and all its records?') && run(api('/dns/' + z.id + '/delete', {}))}>Delete</button></div>
          {open === z.id && <>
            <div className="wrap"><table className="table"><thead><tr><th>Type</th><th>Name</th><th>Value</th><th>TTL</th><th /></tr></thead><tbody>
              {z.records.map((x) => <tr key={x.id}><td>{x.type}</td><td>{x.name}</td><td style={{ wordBreak: 'break-all' }}>{x.prio !== null ? x.prio + ' ' : ''}{x.value}</td><td>{x.ttl}</td><td className="right"><button className="btn sm danger" onClick={() => run(api('/dns/' + z.id + '/records/' + x.id + '/delete', {}))}>Delete</button></td></tr>)}
            </tbody></table></div>
            <form className="row" onSubmit={(e) => { e.preventDefault(); run(api('/dns/' + z.id + '/records', { ...r, prio: r.prio === '' ? null : Number(r.prio) }).then(() => setR({ ...r, value: '' }))) }}>
              <label>Type<select value={r.type} onChange={(e) => setR({ ...r, type: e.target.value })}>{d.types.map((t) => <option key={t}>{t}</option>)}</select></label>
              <label>Name<input value={r.name} onChange={(e) => setR({ ...r, name: e.target.value })} /></label>
              <label style={{ minWidth: 240 }}>Value<input value={r.value} onChange={(e) => setR({ ...r, value: e.target.value })} required /></label>
              {['MX', 'SRV'].includes(r.type) && <label>Priority<input type="number" value={r.prio} onChange={(e) => setR({ ...r, prio: e.target.value })} required /></label>}
              <label>TTL<input type="number" value={r.ttl} onChange={(e) => setR({ ...r, ttl: e.target.value })} /></label>
              <button className="btn primary">Add record</button>
            </form></>}
        </div>
      ))}
    </>
  )
}

export function Ftp() {
  const [d, load, err, setErr] = useData('/ftp')
  const [sites, setSites] = useState([])
  const [f, setF] = useState({ site_id: '', name: '', folder: '' })
  const [secret, setSecret] = useState(null)
  useEffect(() => { api('/sites').then((r) => { setSites(r.sites); if (r.sites[0]) setF((x) => ({ ...x, site_id: r.sites[0].id })) }) }, [])
  const run = (p, title) => p.then((r) => { setErr(''); if (r.password) setSecret({ title, value: 'User: ' + r.name + '   Password: ' + r.password }); load() }).catch((e) => setErr(e.message))
  return (
    <>
      <h1>FTP users</h1>
      {secret && <Secret text={secret} onClose={() => setSecret(null)} />}
      {err && <div className="alert bad">{err}</div>}
      <form className="card row" onSubmit={(e) => { e.preventDefault(); run(api('/ftp', { ...f, site_id: Number(f.site_id) }), 'FTP user created') }}>
        <label>Website<select value={f.site_id} onChange={(e) => setF({ ...f, site_id: e.target.value })}>{sites.map((s) => <option key={s.id} value={s.id}>{s.domain}</option>)}</select></label>
        <label>User name<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></label>
        <label>Folder inside the site (optional)<input value={f.folder} onChange={(e) => setF({ ...f, folder: e.target.value })} placeholder="public_html" /></label>
        <button className="btn primary">Create user</button>
      </form>
      {!d ? <p className="muted">Loading...</p> : d.users.length === 0 ? <div className="card muted">No FTP users yet.</div> : (
        <div className="card wrap"><table className="table"><thead><tr><th>User</th><th>Website</th><th>Folder</th><th /></tr></thead><tbody>
          {d.users.map((u) => <tr key={u.id}><td><strong>{u.name}</strong></td><td>{u.domain}</td><td className="code">{u.dir}</td><td className="right">
            <button className="btn sm" onClick={() => window.confirm('Make a new password for ' + u.name + '?') && run(api('/ftp/' + u.id + '/password', {}), 'New FTP password')}>New password</button>
            <button className="btn sm danger" onClick={() => window.confirm('Delete FTP user ' + u.name + '?') && run(api('/ftp/' + u.id + '/delete', {}))}>Delete</button></td></tr>)}
        </tbody></table></div>
      )}
    </>
  )
}

export function Redis() {
  const [d, load, err, setErr] = useData('/redis')
  const [f, setF] = useState({ name: '', maxmem_mb: 128 })
  const [secret, setSecret] = useState(null)
  const run = (p) => p.then((r) => { setErr(''); if (r.password) setSecret({ title: 'Redis ' + r.name + ' is ready', value: '127.0.0.1:' + r.port + '   Password: ' + r.password }); load() }).catch((e) => setErr(e.message))
  return (
    <>
      <h1>Redis</h1>
      {secret && <Secret text={secret} onClose={() => setSecret(null)} />}
      {err && <div className="alert bad">{err}</div>}
      <form className="card row" onSubmit={(e) => { e.preventDefault(); run(api('/redis', { ...f, maxmem_mb: Number(f.maxmem_mb) })) }}>
        <label>Name<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="cache" required /></label>
        <label>Memory limit (MB)<input type="number" min="16" max="4096" value={f.maxmem_mb} onChange={(e) => setF({ ...f, maxmem_mb: e.target.value })} /></label>
        <button className="btn primary">Create instance</button>
      </form>
      {!d ? <p className="muted">Loading...</p> : d.instances.length === 0 ? <div className="card muted">No Redis instances yet.</div> : (
        <div className="card wrap"><table className="table"><thead><tr><th>Name</th><th>Port</th><th>Memory</th><th /></tr></thead><tbody>
          {d.instances.map((i) => <tr key={i.id}><td><strong>{i.name}</strong></td><td>{i.port}</td><td>{i.maxmem_mb} MB</td><td className="right">
            <button className="btn sm" onClick={() => run(api('/redis/' + i.id + '/restart', {}))}>Restart</button>
            <button className="btn sm danger" onClick={() => window.confirm('Delete Redis ' + i.name + ' and its data?') && run(api('/redis/' + i.id + '/delete', {}))}>Delete</button></td></tr>)}
        </tbody></table></div>
      )}
    </>
  )
}

export function Docker() {
  const [d, load, err, setErr] = useData('/docker')
  const [f, setF] = useState({ template: 'redis', name: '', port: '', public: false })
  const [logs, setLogs] = useState(null)
  const [secret, setSecret] = useState(null)
  const run = (p) => p.then((r) => { setErr(''); if (r.password) setSecret({ title: 'Container ' + r.name + ' started', value: 'Port ' + r.port + '   Password: ' + r.password }); load() }).catch((e) => setErr(e.message))
  if (d && !d.available) return <><h1>Docker</h1><div className="card muted">Docker is not installed on this server. Install it (apt install docker.io) and reload this page.</div></>
  return (
    <>
      <h1>Docker</h1>
      {secret && <Secret text={secret} onClose={() => setSecret(null)} />}
      {err && <div className="alert bad">{err}</div>}
      {d && <form className="card row" onSubmit={(e) => { e.preventDefault(); run(api('/docker/run', { ...f, port: Number(f.port) })) }}>
        <label>Service<select value={f.template} onChange={(e) => setF({ ...f, template: e.target.value })}>{Object.entries(d.templates).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
        <label>Container name<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder={f.template} /></label>
        <label>Port on this server<input type="number" min="1024" max="65535" value={f.port} onChange={(e) => setF({ ...f, port: e.target.value })} required /></label>
        <label className="row"><input type="checkbox" checked={f.public} onChange={(e) => setF({ ...f, public: e.target.checked })} /> Open to the internet (otherwise only this server)</label>
        <button className="btn primary">Start</button>
      </form>}
      {logs && <div className="card"><div className="row"><strong>Logs of {logs.name}</strong><button className="btn sm" onClick={() => setLogs(null)}>Close</button></div><pre className="code" style={{ maxHeight: 320, overflow: 'auto', whiteSpace: 'pre-wrap' }}>{logs.text}</pre></div>}
      {!d ? <p className="muted">Loading...</p> : d.containers.length === 0 ? <div className="card muted">No containers yet.</div> : (
        <div className="card wrap"><table className="table"><thead><tr><th>Name</th><th>Image</th><th>Status</th><th>Ports</th><th /></tr></thead><tbody>
          {d.containers.map((c) => <tr key={c.name}><td><strong>{c.name}</strong></td><td>{c.image}</td><td>{c.status}</td><td className="small">{c.ports}</td><td className="right">
            {c.state === 'running' ? <button className="btn sm" onClick={() => run(api('/docker/' + c.name + '/stop', {}))}>Stop</button> : <button className="btn sm" onClick={() => run(api('/docker/' + c.name + '/start', {}))}>Start</button>}
            <button className="btn sm" onClick={() => api('/docker/' + c.name + '/logs').then((r) => setLogs({ name: c.name, text: r.logs })).catch((e) => setErr(e.message))}>Logs</button>
            <button className="btn sm danger" onClick={() => window.confirm('Remove ' + c.name + '? Data inside it is lost.') && run(api('/docker/' + c.name + '/remove', {}))}>Remove</button></td></tr>)}
        </tbody></table></div>
      )}
    </>
  )
}

export function Mail() {
  const [d, load, err, setErr] = useData('/mail')
  const [dom, setDom] = useState('')
  const [open, setOpen] = useState(null)
  const [m, setM] = useState({ local: '', quota_mb: 1024, password: '' })
  const [a, setA] = useState({ local: '', target: '' })
  const [secret, setSecret] = useState(null)
  const run = (p) => p.then((r) => { setErr(''); if (r.password) setSecret({ title: 'Mailbox ' + r.address + ' created', value: 'Password: ' + r.password }); load() }).catch((e) => setErr(e.message))
  return (
    <>
      <h1>Mail</h1>
      {secret && <Secret text={secret} onClose={() => setSecret(null)} />}
      {err && <div className="alert bad">{err}</div>}
      <form className="card row" onSubmit={(e) => { e.preventDefault(); run(api('/mail', { domain: dom }).then(() => setDom(''))) }}>
        <label style={{ minWidth: 260 }}>Mail domain<input value={dom} onChange={(e) => setDom(e.target.value)} placeholder="example.com" required /></label>
        <button className="btn primary">Add domain</button>
      </form>
      {!d ? <p className="muted">Loading...</p> : d.domains.length === 0 ? <div className="card muted">No mail domains yet.</div> : d.domains.map((x) => (
        <div key={x.id} className="card">
          <div className="row"><strong>{x.domain}</strong><span className="muted small">{x.mailboxes.length} mailboxes, {x.aliases.length} forwards</span>
            <button className="btn sm" onClick={() => setOpen(open === x.id ? null : x.id)}>{open === x.id ? 'Close' : 'Manage'}</button>
            <button className="btn sm danger" onClick={() => window.confirm('Remove ' + x.domain + ' with all its mailboxes?') && run(api('/mail/' + x.id + '/delete', {}))}>Remove</button></div>
          {open === x.id && <>
            <h3>Add these to the DNS of {x.domain}</h3>
            <div className="wrap"><table className="table"><tbody>{x.dns.map(([t, n, v], i) => <tr key={i}><td>{t}</td><td>{n}</td><td className="code" style={{ wordBreak: 'break-all' }}>{v}</td></tr>)}</tbody></table></div>
            <h3>Mailboxes</h3>
            <button className="btn sm" onClick={async () => { const csv = await ask('Import mailboxes: one per line as address,password,size MB (lines separated by ;)'); if (csv) api('/mail/' + x.id + '/import', { csv: csv.split(';').join('\n') }).then((r) => { setSecret({ title: 'Imported ' + r.added.length + ' mailbox(es)', value: r.added.join(', ') + (r.failed.length ? '   Not imported: ' + r.failed.join('; ') : '') }); load() }).catch((e) => setErr(e.message)) }}>Import from CSV</button>
            <button className="btn sm" onClick={async () => { const j = await ask('Spam filter: score from which mail goes to Junk (0 = filter off)', '6'); if (j !== null) api('/mail/' + x.id + '/spam', { on: Number(j) > 0, junk: Number(j) || 6, reject: 15 }).then(() => alert('Spam filter saved.')).catch((e) => setErr(e.message)) }}>Spam filter</button>
            <button className="btn sm" onClick={() => api('/mail/' + x.id + '/usage').then((r) => setSecret({ title: 'Mailbox disk use', value: r.usage.map((u) => u.address + ': ' + Math.round(u.bytes / 1048576) + ' of ' + u.quota_mb + ' MB').join('   ') })).catch((e) => setErr(e.message))}>Disk use</button>
            <button className="btn sm" onClick={async () => { const l = await ask('Mailing list address (before the @)'); if (!l) return; const m = await ask('Members, separated by commas'); if (m) run(api('/mail/' + x.id + '/lists', { local: l, members: m.split(',') })) }}>Mailing list</button>
            <button className="btn sm" onClick={async () => { const mode = await ask('MTA-STS (other mail servers must use encryption to reach you): testing, enforce or none', 'testing'); if (!mode) return; const rep = await ask('Address for TLS reports (optional)', '') || ''; api('/mail/' + x.id + '/mta-sts', { mode: mode.trim(), report: rep.trim() }).then((r) => setSecret({ title: 'MTA-STS for ' + r.domain + (r.dns_added ? ' (DNS records added here)' : ' - add these DNS records'), value: r.records.map((q) => q.type + '  ' + q.name + '  ' + q.value).join('\n') + '\n\n' + r.next })).catch((e2) => setErr(e2.message)) }}>MTA-STS</button>
            <button className="btn sm" onClick={async () => { const em = await ask('E-mail for certificate notices (certificate for mta-sts.' + x.domain + ')'); if (em) run(api('/mail/' + x.id + '/mta-sts/cert', { email: em })) }}>Certificate for mta-sts</button>
            <button className="btn sm" onClick={() => api('/mail/' + x.id + '/check').then((r) => setSecret({ title: 'Deliverability of ' + r.domain + ': ' + r.score, value: r.checks.map((c) => (c.ok ? 'OK   ' : 'FIX  ') + c.check + (c.ok ? '' : ' - ' + c.fix)).join('\n') })).catch((e) => setErr(e.message))}>Check deliverability</button>
            {x.mailboxes.map((b) => <div key={b.id} className="row"><span>{b.local}@{x.domain} <span className="muted small">{b.quota_mb} MB{b.autoreply && b.autoreply.includes('"message"') ? ', auto reply on' : ''}{b.autoreply && b.autoreply.includes('"filters"') ? ', filters' : ''}</span></span>
              <button className="btn sm" onClick={async () => { if (b.autoreply && b.autoreply.includes('"message"')) { run(api('/mail/' + x.id + '/mailboxes/' + b.id + '/autoreply', { on: false })); return } const t = await ask('Auto reply text (out of office)'); if (t) run(api('/mail/' + x.id + '/mailboxes/' + b.id + '/autoreply', { on: true, subject: 'Out of office', message: t, days: 1 })) }}>{b.autoreply && b.autoreply.includes('"message"') ? 'Stop auto reply' : 'Auto reply'}</button>
              <button className="btn sm" onClick={async () => { const t = await ask('Filter: put mail whose subject contains this text into a folder (empty = remove all filters)'); if (t === null) return; const folder = t ? await ask('Folder name', 'Filtered') : ''; if (t && !folder) return; run(api('/mail/' + x.id + '/mailboxes/' + b.id + '/filters', { filters: t ? [{ field: 'subject', contains: t, action: 'folder', target: folder }] : [] })) }}>Filter</button><button className="btn sm danger" onClick={() => window.confirm('Delete ' + b.local + '@' + x.domain + ' and its mail?') && run(api('/mail/' + x.id + '/mailboxes/' + b.id + '/delete', {}))}>Delete</button></div>)}
            <form className="row" onSubmit={(e) => { e.preventDefault(); run(api('/mail/' + x.id + '/mailboxes', { ...m, quota_mb: Number(m.quota_mb) }).then(() => setM({ local: '', quota_mb: 1024, password: '' }))) }}>
              <label>Address<input value={m.local} onChange={(e) => setM({ ...m, local: e.target.value })} placeholder="info" required /></label>
              <label>Size (MB)<input type="number" value={m.quota_mb} onChange={(e) => setM({ ...m, quota_mb: e.target.value })} /></label>
              <label>Password (empty = make one)<input value={m.password} onChange={(e) => setM({ ...m, password: e.target.value })} /></label>
              <button className="btn primary">Add mailbox</button>
            </form>
            <h3>Forwards</h3>
            {x.aliases.map((y) => <div key={y.id} className="row"><span>{y.local}@{x.domain} goes to {y.target}</span></div>)}
            <form className="row" onSubmit={(e) => { e.preventDefault(); run(api('/mail/' + x.id + '/aliases', a).then(() => setA({ local: '', target: '' }))) }}>
              <label>From (use * for all other)<input value={a.local} onChange={(e) => setA({ ...a, local: e.target.value })} required /></label>
              <label>To<input type="email" value={a.target} onChange={(e) => setA({ ...a, target: e.target.value })} required /></label>
              <button className="btn">Add forward</button>
            </form></>}
        </div>
      ))}
    </>
  )
}

export function Terminal() {
  const [cmd, setCmd] = useState('')
  const [out, setOut] = useState([])
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const send = (e) => {
    e.preventDefault(); if (!cmd.trim() || busy) return
    setBusy(true); setErr('')
    api('/terminal', { command: cmd }).then((r) => { setOut((o) => [...o, { cmd, text: r.output, code: r.code }]); setCmd('') }).catch((x) => setErr(x.message)).finally(() => setBusy(false))
  }
  return (
    <>
      <h1>Terminal</h1>
      <p className="muted">Runs one command at a time with a 20 second limit. Every command is written to the audit log. Needs two-step sign-in.</p>
      {err && <div className="alert bad">{err}</div>}
      <div className="card"><pre className="code" style={{ minHeight: 220, maxHeight: 480, overflow: 'auto', whiteSpace: 'pre-wrap' }}>{out.map((o, i) => '$ ' + o.cmd + '\n' + o.text + (o.code ? '[exit ' + o.code + ']\n' : '')).join('\n') || 'Nothing run yet.'}</pre>
        <form className="row" onSubmit={send}><input style={{ flex: 1 }} value={cmd} onChange={(e) => setCmd(e.target.value)} placeholder="uptime" aria-label="Command" autoFocus /><button className="btn primary" disabled={busy}>{busy ? 'Running...' : 'Run'}</button></form></div>
    </>
  )
}
