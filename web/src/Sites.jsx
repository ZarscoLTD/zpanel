import { ask, show } from './dialog.jsx'
import { SiteExtras } from './Tools.jsx'
import { SiteToolsPlus, RecycleBin, shareFile } from './Plus.jsx'
import React, { useEffect, useRef, useState } from 'react'
import { api, upload, bytes } from './api.js'

export function Sites({ me }) {
  const [list, setList] = useState(null)
  const [info, setInfo] = useState(null)
  const [adding, setAdding] = useState(false)
  const [open, setOpen] = useState(null)
  const [err, setErr] = useState('')
  const load = () => api('/sites').then((r) => setList(r.sites)).catch((e) => setErr(e.message))
  useEffect(() => { load(); api('/php-versions').then(setInfo) }, [])
  return (
    <>
      <div className="row sp"><h1>Websites</h1><button className="btn primary" onClick={() => setAdding(true)}>Add website</button></div>
      {err && <div className="alert bad">{err}</div>}
      {!list ? <p className="muted">Loading...</p> : list.length === 0 ? <div className="card muted">No websites yet. Add one, or install an app from the App store.</div> : (
        <div className="card wrap"><table className="table"><thead><tr><th>Domain</th><th>Type</th><th>PHP</th><th>SSL</th><th>Status</th>{me.user.role !== 'client' && <th>Owner</th>}<th /></tr></thead><tbody>
          {list.map((s) => <tr key={s.id}><td><strong>{s.domain}</strong>{s.app && <span className="pill" style={{ marginLeft: 6 }}>{s.app}</span>}</td><td>{s.type}</td><td>{s.php || '-'}</td><td>{s.ssl ? <span className="pill on">https</span> : <span className="pill">none</span>}</td>
            <td><span className={'pill ' + (s.enabled ? 'on' : 'off')}>{s.enabled ? 'on' : 'off'}</span></td>{me.user.role !== 'client' && <td>{s.owner}</td>}<td><button className="btn sm" onClick={() => setOpen(s)}>Manage</button> <button className="btn sm" onClick={async () => { const d = await ask('New main domain for ' + s.domain + ' (its folder moves; HTTPS must be issued again for the new name)', s.domain); if (d && d !== s.domain) api('/sites/' + s.id + '/rename', { domain: d }).then(load).catch((x) => setErr(x.message)) }}>Change domain</button></td></tr>)}
        </tbody></table></div>
      )}
      {adding && info && <Add info={info} me={me} onClose={() => setAdding(false)} onDone={() => { setAdding(false); load() }} />}
      {open && <Manage site={open} info={info} onClose={() => { setOpen(null); load() }} />}
    </>
  )
}

function Add({ info, onClose, onDone }) {
  const [f, setF] = useState({ domain: '', type: 'php', php: info.versions[info.versions.length - 1], aliases: '', rewrite: 'none', proxy_to: 'http://127.0.0.1:3000', redirect_to: 'https://', public_dir: false })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })
  const save = (e) => { e.preventDefault(); setErr(''); setBusy(true); api('/sites', f).then(onDone).catch((x) => { setErr(x.message); setBusy(false) }) }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Add website"><form className="card" onSubmit={save}>
      <h2>Add website</h2>{err && <div className="alert bad" role="alert">{err}</div>}
      <div className="form">
        <label>Domain<input value={f.domain} onChange={set('domain')} placeholder="example.com" required autoFocus /></label>
        <label>Type<select value={f.type} onChange={set('type')}><option value="php">PHP website</option><option value="static">Static files</option><option value="proxy">App behind a proxy (Node, Python...)</option><option value="redirect">Redirect to another address</option></select></label>
        <label className="full">Other names (www, aliases), separated by commas<input value={f.aliases} onChange={set('aliases')} placeholder={'www.' + (f.domain || 'example.com')} /></label>
        {f.type === 'php' && <><label>PHP version<select value={f.php} onChange={set('php')}>{info.versions.map((v) => <option key={v}>{v}</option>)}</select></label>
          <label>Rewrite rules<select value={f.rewrite} onChange={set('rewrite')}>{info.rewrites.map((r) => <option key={r}>{r}</option>)}</select></label></>}
        {f.type !== 'proxy' && f.type !== 'redirect' && <label style={{ flexDirection: 'row', display: 'flex', gap: 8, alignItems: 'center' }}><input type="checkbox" style={{ width: 'auto' }} checked={f.public_dir} onChange={set('public_dir')} /> The site starts in a "public" folder (Laravel)</label>}
        {f.type === 'proxy' && <label className="full">Send visitors to<input value={f.proxy_to} onChange={set('proxy_to')} /></label>}
        {f.type === 'redirect' && <label className="full">Redirect to<input value={f.redirect_to} onChange={set('redirect_to')} /></label>}
      </div>
      <div className="row" style={{ marginTop: 14 }}><button className="btn primary" disabled={busy}>{busy ? 'Creating...' : 'Create'}</button><button type="button" className="btn" onClick={onClose}>Cancel</button></div>
    </form></div>
  )
}

function Manage({ site: s0, info, onClose }) {
  const [s, setS] = useState(s0)
  const [tab, setTab] = useState('settings')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const run = (p, m) => { setErr(''); setMsg(''); return p.then((r) => { if (r.site) setS(r.site); setMsg(m || 'Saved.'); return r }).catch((x) => setErr(x.message)) }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={'Manage ' + s.domain}><div className="card" style={{ width: 'min(920px,100%)' }}>
      <div className="row sp"><h2>{s.domain}</h2><button className="btn sm" onClick={onClose}>Close</button></div>
      <div className="tabs" role="tablist">{[['settings', 'Settings'], ['ssl', 'SSL'], ['files', 'Files'], ['extras', 'Extras'], ['tools', 'Tools'], ['logs', 'Logs']].filter(([k]) => ['php', 'static'].includes(s.type) || k === 'settings' || k === 'logs' || k === 'tools').map(([k, l]) => <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}>{l}</button>)}</div>
      {err && <div className="alert bad" role="alert">{err}</div>}{msg && <div className="alert good" role="status">{msg}</div>}
      {tab === 'settings' && <SiteSettings s={s} info={info} run={run} onGone={onClose} />}
      {tab === 'ssl' && <Ssl s={s} run={run} />}
      {tab === 'files' && <FileManager s={s} />}
      {tab === 'extras' && <SiteExtras s={s} />}
      {tab === 'tools' && <SiteToolsPlus s={s} />}
      {tab === 'logs' && <SiteLogs s={s} />}
    </div></div>
  )
}

function SiteSettings({ s, info, run, onGone }) {
  const [f, setF] = useState({ aliases: s.aliases.split(',').filter(Boolean).join(', '), rewrite: s.rewrite || 'none', php: s.php || '', force_https: !!s.force_https })
  return (
    <>
      <div className="form">
        <label className="full">Other names<input value={f.aliases} onChange={(e) => setF({ ...f, aliases: e.target.value })} /></label>
        {s.type === 'php' && <><label>PHP version<select value={f.php} onChange={(e) => setF({ ...f, php: e.target.value })}>{(info?.versions || [s.php]).map((v) => <option key={v}>{v}</option>)}</select></label>
          <label>Rewrite rules<select value={f.rewrite} onChange={(e) => setF({ ...f, rewrite: e.target.value })}>{(info?.rewrites || ['none']).map((r) => <option key={r}>{r}</option>)}</select></label></>}
        <label style={{ display: 'flex', flexDirection: 'row', gap: 8, alignItems: 'center' }}><input type="checkbox" style={{ width: 'auto' }} checked={f.force_https} disabled={!s.ssl} onChange={(e) => setF({ ...f, force_https: e.target.checked })} /> Send every visitor to https{!s.ssl && ' (get a certificate first)'}</label>
      </div>
      <div className="row" style={{ marginTop: 14 }}>
        <button className="btn primary" onClick={() => run(api('/sites/' + s.id, f))}>Save</button>
        <button className="btn" onClick={() => run(api('/sites/' + s.id + '/toggle', { on: !s.enabled }), s.enabled ? 'The site is switched off.' : 'The site is on.')}>{s.enabled ? 'Switch off' : 'Switch on'}</button>
        <button className="btn danger" onClick={() => { const files = window.confirm('Delete ' + s.domain + '?\n\nOK also deletes its files. Cancel keeps everything.') ; if (window.confirm('Really remove the website ' + s.domain + (files ? ' AND its files' : ' (files stay)') + '?')) api('/sites/' + s.id + '/delete', { files }).then(onGone) }}>Delete</button>
      </div>
    </>
  )
}

function Ssl({ s, run }) {
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <>
      <p>{s.ssl ? <span className="pill on">This site has a certificate</span> : <span className="pill">No certificate yet</span>}</p>
      <p className="muted small">A free certificate from Let's Encrypt. The domain (and every other name) must already point to this server. It renews by itself.</p>
      <div className="row"><label style={{ minWidth: 260 }}>E-mail for certificate notices<input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@example.com" /></label>
        <button className="btn primary" disabled={busy || !email} onClick={() => { setBusy(true); run(api('/sites/' + s.id + '/ssl', { email }), 'Certificate installed.').finally(() => setBusy(false)) }}>{busy ? 'Working (up to a minute)...' : s.ssl ? 'Renew / reissue' : 'Get certificate'}</button>
        <button className="btn" disabled={busy || !email} title="example.com and *.example.com - needs the DNS zone on this server" onClick={() => { setBusy(true); run(api('/sites/' + s.id + '/ssl/wildcard', { email }), 'Wildcard certificate installed.').finally(() => setBusy(false)) }}>Wildcard (*.{s.domain})</button></div>
    </>
  )
}

function FileManager({ s }) {
  const [path, setPath] = useState('')
  const [files, setFiles] = useState(null)
  const [edit, setEdit] = useState(null)
  const [err, setErr] = useState('')
  const [bin, setBin] = useState(false)
  const inp = useRef(null)
  const load = () => api('/sites/' + s.id + '/files?path=' + encodeURIComponent(path)).then((r) => { setFiles(r.files); setErr('') }).catch((e) => setErr(e.message))
  useEffect(() => { load() }, [path])
  const join = (n) => (path ? path + '/' + n : n)
  const op = (o, body) => api('/sites/' + s.id + '/files/' + o, body).then(load).catch((e) => setErr(e.message))
  const parts = path.split('/').filter(Boolean)
  return (
    <>
      <div className="row" style={{ marginBottom: 8 }}>
        <button className="btn sm" onClick={() => setPath('')}>Site folder</button>{parts.map((p, i) => <button key={i} className="btn sm" onClick={() => setPath(parts.slice(0, i + 1).join('/'))}>/ {p}</button>)}
        <span style={{ flex: 1 }} />
        <button className="btn sm" onClick={async () => { const n = await ask('New folder name'); if (n) op('mkdir', { path: join(n) }) }}>New folder</button>
        <button className="btn sm" onClick={async () => { const n = await ask('New file name'); if (n) api('/sites/' + s.id + '/file', { path: join(n), content: '' }).then(load).catch((e) => setErr(e.message)) }}>New file</button>
        <button className="btn sm" onClick={() => inp.current.click()}>Upload</button>
        <button className="btn sm" onClick={() => setBin(true)}>Recycle bin</button>
        <input ref={inp} type="file" multiple hidden onChange={(e) => upload('/sites/' + s.id + '/upload?path=' + encodeURIComponent(path), e.target.files).then(load).catch((x) => setErr(x.message))} />
      </div>
      {err && <div className="alert bad">{err}</div>}
      {!files ? <p className="muted">Loading...</p> : (
        <div className="wrap"><table className="table"><thead><tr><th>Name</th><th>Size</th><th>Changed</th><th>Mode</th><th /></tr></thead><tbody>
          {files.length === 0 && <tr><td colSpan="5" className="muted">This folder is empty.</td></tr>}
          {files.map((f) => <tr key={f.name}><td>{f.dir ? <button className="btn sm" onClick={() => setPath(join(f.name))}>{f.name}/</button> : <a href="#" onClick={(e) => { e.preventDefault(); api('/sites/' + s.id + '/file?path=' + encodeURIComponent(join(f.name))).then((r) => setEdit({ name: join(f.name), content: r.content })).catch((x) => setErr(x.message)) }}>{f.name}</a>}</td>
            <td>{f.dir ? '-' : bytes(f.size)}</td><td className="small">{f.modified.slice(0, 16).replace('T', ' ')}</td><td><span className="code">{f.mode}</span></td>
            <td className="row">
              <button className="btn sm" onClick={async () => { const n = await ask('New name', f.name); if (n && n !== f.name) op('rename', { path: join(f.name), to: join(n) }) }}>Rename</button>
              <button className="btn sm" onClick={async () => { const m = await ask('Permissions (for example 755 or 644)', f.mode.slice(-3)); if (m) op('chmod', { path: join(f.name), mode: m }) }}>Mode</button>
              {f.name.endsWith('.zip') ? <button className="btn sm" onClick={() => op('unzip', { path: join(f.name), to: path })}>Unzip</button> : <button className="btn sm" onClick={() => op('zip', { path: join(f.name), to: join(f.name + '.zip') })}>Zip</button>}
              {!f.dir && <button className="btn sm" onClick={() => shareFile(s, join(f.name), setErr)}>Share</button>}
              <button className="btn sm danger" onClick={() => window.confirm('Move ' + f.name + ' to the recycle bin?') && op('delete', { path: join(f.name) })}>Delete</button></td></tr>)}
        </tbody></table></div>
      )}
      {bin && <RecycleBin s={s} onClose={() => setBin(false)} onChange={load} />}
      {edit && <div className="modal" role="dialog" aria-modal="true"><div className="card" style={{ width: 'min(900px,100%)' }}>
        <h2>{edit.name}</h2>
        <textarea className="editor" value={edit.content} onChange={(e) => setEdit({ ...edit, content: e.target.value })} spellCheck={false} aria-label="File content" />
        <div className="row" style={{ marginTop: 10 }}><button className="btn primary" onClick={() => api('/sites/' + s.id + '/file', { path: edit.name, content: edit.content }).then(() => { setEdit(null); load() }).catch((x) => setErr(x.message))}>Save</button><button className="btn" onClick={() => setEdit(null)}>Close</button></div>
      </div></div>}
    </>
  )
}

function SiteLogs({ s }) {
  const [type, setType] = useState('error')
  const [lines, setLines] = useState(null)
  useEffect(() => { api('/sites/' + s.id + '/logs?type=' + type).then((r) => setLines(r.lines)) }, [type])
  return (
    <>
      <div className="tabs"><button aria-selected={type === 'error'} onClick={() => setType('error')}>Errors</button><button aria-selected={type === 'access'} onClick={() => setType('access')}>Visits</button></div>
      {!lines ? <p className="muted">Loading...</p> : lines.length === 0 ? <p className="muted">Nothing logged yet.</p> : <pre className="log">{lines.join('\n')}</pre>}
    </>
  )
}

// One tool of a website on its own page, like cPanel (owner 2026-11-14): pick the domain at the top, the tool shows below it.
const TAB_KEY = { Settings: 'settings', SSL: 'ssl', Files: 'files', Extras: 'extras', Tools: 'tools', Logs: 'logs' }
export function SiteTool({ feature }) {
  const [list, setList] = useState(null)
  const [info, setInfo] = useState(null)
  const [id, setId] = useState(() => { try { return localStorage.getItem('zp-tool-site') || '' } catch (e) { return '' } })
  const [s, setS] = useState(null)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  useEffect(() => { api('/sites').then((r) => setList(r.sites)).catch((e) => setErr(e.message)); api('/php-versions').then(setInfo).catch(() => {}) }, [])
  useEffect(() => {
    if (!list) return
    const pick = list.find((x) => String(x.id) === String(id)) || list[0]
    setS(pick || null)
    if (pick) { try { localStorage.setItem('zp-tool-site', String(pick.id)) } catch (e) { /* private window */ } }
  }, [list, id])
  if (!feature) return <div className="alert bad">This tool was not found.</div>
  const tab = TAB_KEY[feature.siteTab] || 'settings'
  const run = (p, m) => { setErr(''); setMsg(''); return p.then((r) => { if (r.site) setS(r.site); setMsg(m || 'Saved.'); return r }).catch((x) => setErr(x.message)) }
  return (
    <>
      <div className="row sp" style={{ flexWrap: 'wrap', gap: 10 }}>
        <div><h1>{feature.name}</h1><p className="muted" style={{ margin: 0 }}>{feature.text}</p></div>
        {list && list.length > 0 && <label className="field" style={{ minWidth: 220 }}><span>Domain</span>
          <select value={s ? s.id : ''} onChange={(e) => setId(e.target.value)}>{list.map((x) => <option key={x.id} value={x.id}>{x.domain}</option>)}</select></label>}
      </div>
      {err && <div className="alert bad" role="alert">{err}</div>}{msg && <div className="alert good" role="status">{msg}</div>}
      {!list ? <p className="muted">Loading...</p> : !s ? <div className="card muted">No website yet. Add one in Websites first.</div> : (
        <div className="card" key={s.id + tab}>
          {tab === 'settings' && <SiteSettings s={s} info={info || { versions: [] }} run={run} onGone={() => setId('')} />}
          {tab === 'ssl' && <Ssl s={s} run={run} />}
          {tab === 'files' && <FileManager s={s} />}
          {tab === 'extras' && <SiteExtras s={s} />}
          {tab === 'tools' && <SiteToolsPlus s={s} />}
          {tab === 'logs' && <SiteLogs s={s} />}
        </div>
      )}
    </>
  )
}

