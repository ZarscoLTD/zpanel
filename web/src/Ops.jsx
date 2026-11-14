import { ask, show } from './dialog.jsx'
import React, { useEffect, useState } from 'react'
import { api, bytes } from './api.js'

function useList(path, key) {
  const [list, setList] = useState(null)
  const [err, setErr] = useState('')
  const load = () => api(path).then((r) => setList(r[key])).catch((e) => setErr(e.message))
  useEffect(() => { load() }, [])
  return [list, load, err, setErr]
}

function Secret({ text, onClose }) {
  return (
    <div className="modal" role="dialog" aria-modal="true"><div className="card">
      <h2>{text.title}</h2><p>Copy this now. It is not shown again.</p>
      <p className="code" style={{ fontSize: 16, padding: 10 }}>{text.value}</p>
      <div className="row"><button className="btn" onClick={() => navigator.clipboard && navigator.clipboard.writeText(text.value)}>Copy</button><button className="btn primary" onClick={onClose}>Done</button></div>
    </div></div>
  )
}

export function Databases({ me }) {
  const [list, load, err, setErr] = useList('/databases', 'databases')
  const [name, setName] = useState('')
  const [engine, setEngine] = useState('mysql')
  const [secret, setSecret] = useState(null)
  const create = (e) => { e.preventDefault(); api('/databases', { name, engine }).then((r) => { setSecret({ title: 'Database ' + r.database.name + ' created', value: 'User: ' + r.database.db_user + '   Password: ' + r.password }); setName(''); load() }).catch((x) => setErr(x.message)) }
  return (
    <>
      <h1>Databases</h1>
      {err && <div className="alert bad">{err}</div>}
      <form className="card row" onSubmit={create}><label style={{ minWidth: 260 }}>New database name<input value={name} onChange={(e) => setName(e.target.value)} placeholder="shop_db" required /></label><label>Type<select value={engine} onChange={(e) => setEngine(e.target.value)}><option value="mysql">MariaDB / MySQL</option><option value="pgsql">PostgreSQL</option></select></label><button className="btn primary" style={{ alignSelf: 'flex-end' }}>Create database</button></form>
      {!list ? <p className="muted">Loading...</p> : list.length === 0 ? <div className="card muted">No databases yet.</div> : (
        <div className="card wrap"><table className="table"><thead><tr><th>Name</th><th>Type</th><th>User</th>{me.user.role !== 'client' && <th>Owner</th>}<th /></tr></thead><tbody>
          {list.map((d) => <tr key={d.id}><td><strong>{d.name}</strong></td><td>{d.engine === 'pgsql' ? 'PostgreSQL' : 'MariaDB'}</td><td>{d.db_user}</td>{me.user.role !== 'client' && <td>{d.owner}</td>}<td className="row">
            {me.user.role === 'owner' && <button className="btn sm" onClick={async () => { const n = await ask('Give ' + d.name + ' to which user (user name)?'); if (n) api('/users').then((r) => { const t = r.users.find((x) => x.name === n.trim()); if (!t) throw new Error('No user ' + n); return api('/plus/databases/' + d.id + '/owner', { user_id: t.id }) }).then(load).catch((x) => setErr(x.message)) }}>Give to user</button>}
            <button className="btn sm" onClick={() => window.confirm('Create a new password for ' + d.name + '? Sites using the old one will stop working until you update them.') && api('/databases/' + d.id + '/password', {}).then((r) => setSecret({ title: 'New password for ' + d.name, value: r.password })).catch((x) => setErr(x.message))}>New password</button>
            {d.engine !== 'pgsql' && <button className="btn sm" onClick={() => api('/databases/' + d.id + '/phpmyadmin', {}).then((r) => { setSecret({ title: 'phpMyAdmin for ' + d.name, value: 'User: ' + r.user + '   Password: ' + r.password + '   (' + r.note + ')' }); window.open(r.url, '_blank', 'noopener') }).catch((x) => setErr(x.message))}>phpMyAdmin</button>}
            {d.engine !== 'pgsql' && <button className="btn sm" onClick={async () => { const h = await ask('Allow this database user to connect from (IP address, host name or 192.168.1.%)'); if (h) api('/databases/' + d.id + '/remote', { host: h }).then((r) => { setSecret({ title: 'Remote access for ' + d.name + ' from ' + h, value: 'Password: ' + r.password + '   (' + r.note + ')' }); load() }).catch((x) => setErr(x.message)) }}>Remote access{d.remote_hosts ? ' (' + d.remote_hosts.split(',').length + ')' : ''}</button>}
            {d.remote_hosts && d.remote_hosts.split(',').filter(Boolean).map((h) => <button key={h} className="btn sm" onClick={() => window.confirm('Stop access from ' + h + '?') && api('/databases/' + d.id + '/remote/delete', { host: h }).then(load).catch((x) => setErr(x.message))}>Remove {h}</button>)}
            <select aria-label={'Maintenance of ' + d.name} value="" onChange={(e) => e.target.value && api('/databases/' + d.id + '/maintain', { op: e.target.value }).then((r) => setSecret({ title: e.target.value + ' ' + d.name + (r.ok ? ': done' : ': problems found'), value: (r.output || '').slice(0, 1500) })).catch((x) => setErr(x.message))}>
              <option value="">Maintain...</option><option value="check">Check</option><option value="repair">Repair</option><option value="optimize">Optimize</option><option value="analyze">Analyze</option></select>
            <button className="btn sm danger" onClick={() => window.confirm('Delete the database ' + d.name + ' and ALL its data?') && window.confirm('This cannot be undone. Delete ' + d.name + '?') && api('/databases/' + d.id + '/delete', {}).then(load).catch((x) => setErr(x.message))}>Delete</button></td></tr>)}
        </tbody></table></div>
      )}
      <p className="muted small">To import an .sql file, upload it to the website folder with the file manager, then use the API (POST /api/databases/ID/import) or ask your provider.</p>
      {secret && <Secret text={secret} onClose={() => setSecret(null)} />}
    </>
  )
}

const KINDS = { shell: 'Run a command in a website', url: 'Open a web address', backup_site: 'Back up a website', backup_db: 'Back up a database' }
const PRESETS = [['Every minute', '* * * * *'], ['Every 5 minutes', '*/5 * * * *'], ['Every hour', '0 * * * *'], ['Every day at 3:00', '0 3 * * *'], ['Every Sunday at 2:00', '0 2 * * 0']]

export function Cron() {
  const [list, load, err, setErr] = useList('/cron', 'jobs')
  const [sites, setSites] = useState([])
  const [dbs, setDbs] = useState([])
  const [f, setF] = useState({ kind: 'url', name: '', schedule: '0 3 * * *', target: 'https://', command: '', site_id: '', db_id: '' })
  useEffect(() => { api('/sites').then((r) => setSites(r.sites)); api('/databases').then((r) => setDbs(r.databases)) }, [])
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const save = (e) => { e.preventDefault(); setErr(''); api('/cron', f).then(() => { setF({ ...f, name: '', command: '' }); load() }).catch((x) => setErr(x.message)) }
  return (
    <>
      <h1>Scheduled jobs</h1>{err && <div className="alert bad" role="alert">{err}</div>}
      <form className="card" onSubmit={save}><h2>New job</h2><div className="form">
        <label>What should it do?<select value={f.kind} onChange={set('kind')}>{Object.entries(KINDS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
        <label>Name<input value={f.name} onChange={set('name')} placeholder="Nightly backup" /></label>
        <label>When<select value={PRESETS.some(([, v]) => v === f.schedule) ? f.schedule : ''} onChange={(e) => e.target.value && setF({ ...f, schedule: e.target.value })}><option value="">Custom (type on the right)</option>{PRESETS.map(([l, v]) => <option key={v} value={v}>{l}</option>)}</select></label>
        <label>Schedule (minute hour day month weekday)<input value={f.schedule} onChange={set('schedule')} className="code" /></label>
        {f.kind === 'url' && <label className="full">Web address<input value={f.target} onChange={set('target')} /></label>}
        {(f.kind === 'shell' || f.kind === 'backup_site') && <label>Website<select value={f.site_id} onChange={set('site_id')} required><option value="">Choose...</option>{sites.map((s) => <option key={s.id} value={s.id}>{s.domain}</option>)}</select></label>}
        {f.kind === 'shell' && <label className="full">Command (runs inside the website folder)<input value={f.command} onChange={set('command')} className="code" placeholder="php artisan schedule:run" /></label>}
        {f.kind === 'backup_db' && <label>Database<select value={f.db_id} onChange={set('db_id')} required><option value="">Choose...</option>{dbs.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select></label>}
      </div><div style={{ marginTop: 12 }}><button className="btn primary">Add job</button></div></form>
      {!list ? <p className="muted">Loading...</p> : list.length === 0 ? <div className="card muted">No jobs yet.</div> : (
        <div className="card wrap"><table className="table"><thead><tr><th>Name</th><th>Does</th><th>Schedule</th><th>Status</th><th /></tr></thead><tbody>
          {list.map((j) => <tr key={j.id}><td><strong>{j.name}</strong></td><td>{KINDS[j.kind]}</td><td className="code">{j.schedule}</td><td><span className={'pill ' + (j.enabled ? 'on' : 'off')}>{j.enabled ? 'on' : 'paused'}</span></td>
            <td className="row"><button className="btn sm" onClick={() => api('/cron/' + j.id + '/toggle', { on: !j.enabled }).then(load)}>{j.enabled ? 'Pause' : 'Resume'}</button><button className="btn sm danger" onClick={() => window.confirm('Delete this job?') && api('/cron/' + j.id + '/delete', {}).then(load)}>Delete</button></td></tr>)}
        </tbody></table></div>
      )}
    </>
  )
}

export function Backups({ me }) {
  const [list, load, err, setErr] = useList('/backups', 'backups')
  const [sites, setSites] = useState([])
  const [dbs, setDbs] = useState([])
  const [busy, setBusy] = useState('')
  const [cfg, setCfg] = useState({ keep: 7, cloud: false })
  useEffect(() => { api('/sites').then((r) => setSites(r.sites)); api('/databases').then((r) => setDbs(r.databases)); api('/backups').then((r) => setCfg({ keep: r.keep, cloud: r.cloud })) }, [])
  const make = (kind, id, label) => { setBusy(label); setErr(''); api('/backups/' + kind + '/' + id, { cloud: true }).then(load).catch((x) => setErr(x.message)).finally(() => setBusy('')) }
  return (
    <>
      <h1>Backups</h1>{err && <div className="alert bad" role="alert">{err}</div>}
      <div className="card"><h2>Back up now</h2><div className="row">
        {sites.map((s) => <button key={'s' + s.id} className="btn sm" disabled={!!busy} onClick={() => make('site', s.id, s.domain)}>{busy === s.domain ? 'Working...' : 'Site: ' + s.domain}</button>)}
        {dbs.map((d) => <button key={'d' + d.id} className="btn sm" disabled={!!busy} onClick={() => make('database', d.id, d.name)}>{busy === d.name ? 'Working...' : 'Database: ' + d.name}</button>)}
        {sites.length + dbs.length === 0 && <span className="muted">Add a website or database first.</span>}
      </div><p className="muted small">The newest {cfg.keep} backups of each item are kept. {cfg.cloud ? 'Copies also go to your cloud storage.' : 'Cloud storage is not set up (owner: Settings > Backups).'} For automatic backups add a scheduled job.</p></div>
      {!list ? <p className="muted">Loading...</p> : list.length === 0 ? <div className="card muted">No backups yet.</div> : (
        <div className="card wrap"><table className="table"><thead><tr><th>What</th><th>Made</th><th>Size</th><th>Cloud</th><th /></tr></thead><tbody>
          {list.map((b) => <tr key={b.id}><td><strong>{b.subject}</strong> <span className="pill">{b.kind}</span></td><td className="small">{b.created_at.slice(0, 16).replace('T', ' ')}</td><td>{bytes(b.size)}</td><td>{b.remote ? <span className="pill on">yes</span> : '-'}</td>
            <td className="row"><a className="btn sm" href={'/api/backups/' + b.id + '/download'}>Download</a>
              {b.kind === 'site' && <button className="btn sm" onClick={() => api('/backups/' + b.id + '/files').then(async (r) => { const f = await ask('Type the file to bring back (one of ' + r.files.length + ' files), for example:\n' + r.files.slice(0, 8).join('\n'), r.files[0] || ''); if (f) return api('/backups/' + b.id + '/restore-file', { path: f }).then(() => alert('Restored: ' + f)) }).catch((x) => setErr(x.message))}>One file</button>}
              <button className="btn sm" onClick={() => window.confirm('Restore ' + b.subject + ' from this backup? Current files / data of it are replaced.') && api('/backups/' + b.id + '/restore', {}).then(() => alert('Restored.')).catch((x) => setErr(x.message))}>Restore</button>
              <button className="btn sm danger" onClick={() => window.confirm('Delete this backup?') && api('/backups/' + b.id + '/delete', {}).then(load)}>Delete</button></td></tr>)}
        </tbody></table></div>
      )}
    </>
  )
}
