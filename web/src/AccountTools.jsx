import React, { useEffect, useState } from 'react'
import { api, bytes } from './api.js'

// Panel 2.0 part 2c - hosting account tools like cPanel / Webuzo Enduser: network tools, SSH keys, disk usage.

export function NetworkTools() {
  const [tool, setTool] = useState('dns')
  const [host, setHost] = useState('')
  const [out, setOut] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const run = (e) => { e.preventDefault(); setErr(''); setBusy(true); setOut(null); api('/account/network', { tool, host }).then((r) => setOut(r.output)).catch((x) => setErr(x.message)).finally(() => setBusy(false)) }
  return (
    <>
      <h1>Network tools</h1>
      <p className="muted">Look up a domain's DNS records, check that a server answers (ping) and see the route to it (trace route).</p>
      <form className="card form" onSubmit={run}>
        <div className="tabs" role="tablist">{[['dns', 'DNS lookup'], ['ping', 'Ping'], ['trace', 'Trace route']].map(([k, l]) => <button type="button" key={k} role="tab" aria-selected={tool === k} onClick={() => setTool(k)}>{l}</button>)}</div>
        <label className="field"><span>Domain or IP address</span><input value={host} onChange={(e) => setHost(e.target.value)} placeholder="example.com" required /></label>
        <button className="btn primary" disabled={busy}>{busy ? 'Working...' : 'Run'}</button>
      </form>
      {err && <div className="alert bad" role="alert">{err}</div>}
      {out != null && <pre className="card" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{out}</pre>}
    </>
  )
}

export function SshKeys() {
  const [d, setD] = useState(null)
  const [key, setKey] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  useEffect(() => { api('/account/ssh-keys').then(setD).catch((e) => setErr(e.message)) }, [])
  const add = (e) => { e.preventDefault(); setErr(''); setMsg(''); api('/account/ssh-keys', { key }).then((r) => { setD(r); setKey(''); setMsg('Key added.') }).catch((x) => setErr(x.message)) }
  const del = (k) => { if (window.confirm('Remove this key? Whoever uses it cannot sign in any more.')) api('/account/ssh-keys/' + k.id + '/delete', {}).then(setD).catch((x) => setErr(x.message)) }
  return (
    <>
      <h1>SSH access</h1>
      <p className="muted">Sign in to this server with a key instead of a password. Paste the public key (the .pub file); the private key never leaves your computer.</p>
      {err && <div className="alert bad" role="alert">{err}</div>}{msg && <div className="alert good" role="status">{msg}</div>}
      {!d ? <p className="muted">Loading...</p> : (
        <>
          <div className="card">
            <p style={{ marginTop: 0 }}>Linux user: <b>{d.linux_user}</b></p>
            {d.keys.length === 0 ? <p className="muted">No key yet.</p> : (
              <div className="wrap"><table className="table"><thead><tr><th>Name</th><th>Type</th><th>Fingerprint</th><th /></tr></thead><tbody>
                {d.keys.map((k) => <tr key={k.id}><td>{k.comment || '-'}</td><td>{k.type}</td><td className="small" style={{ overflowWrap: 'anywhere' }}>{k.fingerprint}</td><td><button className="btn sm" onClick={() => del(k)}>Remove</button></td></tr>)}
              </tbody></table></div>
            )}
          </div>
          <form className="card form" onSubmit={add}>
            <label className="field"><span>Public key</span><textarea rows={3} value={key} onChange={(e) => setKey(e.target.value)} placeholder="ssh-ed25519 AAAA... you@computer" required /></label>
            <button className="btn primary">Add key</button>
          </form>
        </>
      )}
    </>
  )
}

export function DiskUsage() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  useEffect(() => { api('/account/disk').then(setD).catch((e) => setErr(e.message)) }, [])
  const max = d && d.rows.length ? Math.max(...d.rows.map((r) => r.bytes), 1) : 1
  return (
    <>
      <h1>Disk usage</h1>
      <p className="muted">What takes the space of your account, largest first.</p>
      {err && <div className="alert bad" role="alert">{err}</div>}
      {!d ? <p className="muted">Loading...</p> : (
        <div className="card">
          <p style={{ marginTop: 0 }}><b>{bytes(d.total)}</b>{d.limit ? ' of ' + bytes(d.limit) + ' used' : ' used'}</p>
          {d.limit && <div className={'meter' + (d.total / d.limit >= 0.9 ? ' hot' : '')}><i style={{ width: Math.min(100, Math.round((d.total / d.limit) * 100)) + '%' }} /></div>}
          <p className="muted small">Files and folders (inodes): <b>{(d.files || 0).toLocaleString()}</b>{d.files_limit ? ' of ' + d.files_limit.toLocaleString() : ''}</p>
          {d.files_limit && <div className={'meter' + (d.files / d.files_limit >= 0.9 ? ' hot' : '')}><i style={{ width: Math.min(100, Math.round((d.files / d.files_limit) * 100)) + '%' }} /></div>}
          {d.rows.length === 0 ? <p className="muted">Nothing stored yet.</p> : d.rows.map((r, i) => (
            <div key={i} className="cp-stat">
              <div className="row-between"><span>{r.what} <span className="muted small">{r.kind}{r.files ? ' - ' + r.files.toLocaleString() + ' files' : ''}</span></span><b>{bytes(r.bytes)}</b></div>
              <div className="meter"><i style={{ width: Math.max(1, Math.round((r.bytes / max) * 100)) + '%' }} /></div>
            </div>
          ))}
        </div>
      )}
    </>
  )
}

// Remote MySQL (cPanel "Remote Database Access"): computers outside this server that may connect to a database (DbTools endpoints)
export function RemoteDb() {
  const [dbs, setDbs] = useState(null)
  const [id, setId] = useState('')
  const [host, setHost] = useState('')
  const [secret, setSecret] = useState('')
  const [err, setErr] = useState('')
  const load = () => api('/databases').then((r) => { const my = (r.databases || []).filter((x) => (x.engine || 'mysql') === 'mysql'); setDbs(my); if (!id && my[0]) setId(String(my[0].id)) }).catch((e) => setErr(e.message))
  useEffect(() => { load() }, [])
  const d = dbs && dbs.find((x) => String(x.id) === String(id))
  const hosts = d && d.remote_hosts ? d.remote_hosts.split(',').filter(Boolean) : []
  const add = (e) => { e.preventDefault(); setErr(''); setSecret(''); api('/databases/' + id + '/remote', { host }).then((r) => { setHost(''); setSecret('New password for ' + d.db_user + ': ' + r.password + ' - ' + r.note); load() }).catch((x) => setErr(x.message)) }
  const del = (h) => api('/databases/' + id + '/remote/delete', { host: h }).then(load).catch((x) => setErr(x.message))
  return (
    <>
      <h1>Remote MySQL</h1>
      <p className="muted">Let a program on another computer (your office, a reporting tool) connect to a database. Add only addresses you trust.</p>
      {err && <div className="alert bad" role="alert">{err}</div>}{secret && <div className="alert good" role="status" style={{ overflowWrap: 'anywhere' }}>{secret}</div>}
      {!dbs ? <p className="muted">Loading...</p> : !dbs.length ? <div className="card muted">No MySQL / MariaDB database yet.</div> : (
        <>
          <label className="field" style={{ maxWidth: 360 }}><span>Database</span><select value={id} onChange={(e) => setId(e.target.value)}>{dbs.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
          {d && (
            <div className="card">
              <p style={{ marginTop: 0 }}>User <b>{d.db_user}</b> may also connect from:</p>
              {hosts.length === 0 ? <p className="muted">Only from this server (localhost).</p> : hosts.map((h) => <div key={h} className="row-between" style={{ padding: '6px 0' }}><code>{h}</code><button className="btn sm" onClick={() => del(h)}>Remove</button></div>)}
              <form className="form" onSubmit={add} style={{ marginTop: 10 }}>
                <label className="field"><span>Host (IP, a range with %, or a host name)</span><input value={host} onChange={(e) => setHost(e.target.value)} placeholder="203.0.113.7 or 203.0.113.%" required /></label>
                <button className="btn primary">Add host</button>
              </form>
              <p className="muted small">Adding a host gives the user a new password (shown once). Open port 3306 for that address in Firewall too.</p>
            </div>
          )}
        </>
      )}
    </>
  )
}

// Bandwidth (cPanel "Bandwidth"): data sent, hits and visitors per website for a month
export function Bandwidth() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  useEffect(() => { setD(null); api('/account/bandwidth?month=' + month).then(setD).catch((e) => setErr(e.message)) }, [month])
  const max = d && d.rows.length ? Math.max(...d.rows.map((r) => r.bytes), 1) : 1
  return (
    <>
      <div className="row sp" style={{ flexWrap: 'wrap', gap: 10 }}><h1>Bandwidth</h1><label className="field" style={{ maxWidth: 180 }}><span>Month</span><input type="month" value={month} onChange={(e) => setMonth(e.target.value)} /></label></div>
      <p className="muted">Data your websites sent to visitors, the number of requests and of different visitors.</p>
      {err && <div className="alert bad" role="alert">{err}</div>}
      {!d ? <p className="muted">Loading...</p> : (
        <div className="card">
          <p style={{ marginTop: 0 }}>Total: <b>{bytes(d.total)}</b></p>
          {d.rows.length === 0 ? <p className="muted">No website yet.</p> : d.rows.map((r) => (
            <div key={r.domain} className="cp-stat">
              <div className="row-between" style={{ flexWrap: 'wrap', gap: 6 }}><span>{r.domain}</span><span className="small"><b>{bytes(r.bytes)}</b> - {r.hits} requests - {r.visitors} visitors</span></div>
              <div className="meter"><i style={{ width: Math.max(1, Math.round((r.bytes / max) * 100)) + '%' }} /></div>
            </div>
          ))}
        </div>
      )}
    </>
  )
}

// WHM "View bandwidth / resource usage" + "Modify account ownership": every account against its plan; the owner moves clients
export function AccountsUsage({ me }) {
  const [d, setD] = useState(null)
  const [owners, setOwners] = useState([])
  const [moving, setMoving] = useState(null)
  const [to, setTo] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const isOwner = me.user.role === 'owner'
  const load = () => api('/users-usage').then(setD).catch((e) => setErr(e.message))
  useEffect(() => { load(); if (isOwner) api('/users').then((r) => setOwners(r.users.filter((u) => ['owner', 'reseller'].includes(u.role)))).catch(() => {}) }, [])
  const fmt = (x) => (x.unit === 'bytes' ? bytes(x.used) : x.used) + (x.limit == null ? '' : ' / ' + (x.unit === 'bytes' ? bytes(x.limit) : x.limit))
  const pct = (x) => (x.limit ? Math.min(100, Math.round((x.used / x.limit) * 100)) : null)
  const move = (e) => { e.preventDefault(); setErr(''); api('/users/' + moving.id + '/owner', { parent_id: Number(to) }).then(() => { setMsg(moving.name + ' moved.'); setMoving(null); load() }).catch((x) => setErr(x.message)) }
  const show = ['disk', 'bandwidth', 'sites', 'mail', 'databases']
  return (
    <>
      <h1>Accounts resource usage</h1>
      <p className="muted">Disk, bandwidth this month and what each account uses of its plan. Red: 90% or more.</p>
      {err && <div className="alert bad" role="alert">{err}</div>}{msg && <div className="alert good" role="status">{msg}</div>}
      {!d ? <p className="muted">Loading...</p> : d.accounts.length === 0 ? <div className="card muted">No accounts yet.</div> : (
        <div className="card wrap"><table className="table"><thead><tr><th>Account</th><th>Plan</th>{d.accounts[0].stats.filter((x) => show.includes(x.key)).map((x) => <th key={x.key}>{x.label}</th>)}{isOwner && <th />}</tr></thead><tbody>
          {d.accounts.map((a) => (
            <tr key={a.id}>
              <td><b>{a.name}</b> <span className="muted small">{a.role}{a.active ? '' : ', suspended'}</span></td><td>{a.plan || '-'}</td>
              {a.stats.filter((x) => show.includes(x.key)).map((x) => <td key={x.key} style={pct(x) >= 90 ? { color: 'var(--bad, #dc2626)', fontWeight: 600 } : undefined}>{fmt(x)}</td>)}
              {isOwner && <td>{a.role === 'client' && <button className="btn sm" onClick={() => { setMoving(a); setTo('') }}>Change owner</button>}</td>}
            </tr>
          ))}
        </tbody></table></div>
      )}
      {moving && (
        <div className="modal" role="dialog" aria-modal="true" aria-label="Change owner"><form className="card form" style={{ width: 'min(440px,100%)' }} onSubmit={move}>
          <h2>Change the owner of {moving.name}</h2>
          <label className="field"><span>New owner</span><select value={to} onChange={(e) => setTo(e.target.value)} required><option value="">Choose ...</option>{owners.map((o) => <option key={o.id} value={o.id}>{o.name} ({o.role})</option>)}</select></label>
          <div className="row" style={{ gap: 8 }}><button className="btn primary">Move</button><button type="button" className="btn" onClick={() => setMoving(null)}>Cancel</button></div>
        </form></div>
      )}
    </>
  )
}
