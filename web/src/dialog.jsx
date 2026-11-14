import React, { useEffect, useRef, useState } from 'react'

// In-panel dialogs instead of the browser's prompt boxes (owner 2026-11-14: the panel must look finished and be clear).
//   const v = await ask('New folder name')            -> the text, or null when cancelled
//   const v = await ask('Permissions', '644')         -> with a starting value
//   show('Share link', url)                           -> a value to copy, with a Copy button
let push = null
const queue = []

export function ask(message, value = '') {
  return new Promise((done) => { const d = { kind: 'ask', message: String(message), value: value == null ? '' : String(value), done }; push ? push(d) : queue.push(d) })
}

export function show(message, value) {
  return new Promise((done) => { const d = { kind: 'show', message: String(message), value: value == null ? '' : String(value), done }; push ? push(d) : queue.push(d) })
}

export function DialogHost() {
  const [d, setD] = useState(null)
  const [v, setV] = useState('')
  const [copied, setCopied] = useState(false)
  const input = useRef(null)
  useEffect(() => { push = (x) => { setD(x); setV(x.value); setCopied(false) }; if (queue.length) push(queue.shift()); return () => { push = null } }, [])
  useEffect(() => { if (d && input.current) { input.current.focus(); input.current.select() } }, [d])
  if (!d) return null
  const close = (result) => { const x = d; setD(null); x.done(result); if (queue.length) setTimeout(() => push && push(queue.shift()), 0) }
  // a long question: the first line is the title, the rest explains
  const [title, ...rest] = d.message.split('\n')
  const long = title.length > 70
  const multi = d.kind === 'ask' && /one per line|lines|text|members|csv/i.test(d.message)
  return (
    <div className="dlg-back" onMouseDown={(e) => { if (e.target === e.currentTarget) close(d.kind === 'ask' ? null : undefined) }}>
      <form className="dlg" role="dialog" aria-modal="true" aria-label={title} onSubmit={(e) => { e.preventDefault(); close(d.kind === 'ask' ? v : undefined) }}
        onKeyDown={(e) => { if (e.key === 'Escape') close(d.kind === 'ask' ? null : undefined) }}>
        {long ? <p className="dlg-text">{title}</p> : <h2>{title}</h2>}
        {rest.length > 0 && <p className="muted small dlg-text">{rest.join('\n')}</p>}
        {d.kind === 'ask'
          ? (multi ? <textarea ref={input} rows={4} value={v} onChange={(e) => setV(e.target.value)} /> : <input ref={input} value={v} onChange={(e) => setV(e.target.value)} />)
          : <div className="dlg-copy"><code>{d.value}</code><button type="button" className="btn sm" onClick={() => { try { navigator.clipboard.writeText(d.value); setCopied(true) } catch (e) { /* no clipboard */ } }}>{copied ? 'Copied' : 'Copy'}</button></div>}
        <div className="dlg-actions">
          {d.kind === 'ask' && <button type="button" className="btn" onClick={() => close(null)}>Cancel</button>}
          <button className="btn primary">{d.kind === 'ask' ? 'OK' : 'Done'}</button>
        </div>
      </form>
    </div>
  )
}
