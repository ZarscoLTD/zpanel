import React, { useEffect, useState } from 'react'
import { api } from './api.js'

// Sell hosting with a billing system (WHMCS, Blesta, or your own): the steps, the modules to download, an API token, the API.
export function SellHosting({ me }) {
  const [token, setToken] = useState('')
  const [err, setErr] = useState('')
  const host = window.location.hostname
  const port = window.location.port || '443'
  const make = () => api('/account/tokens', { name: 'Billing system' }).then((r) => setToken(r.token)).catch((e) => setErr(e.message))
  const calls = [
    ['GET', 'test', 'Check the token and the connection'],
    ['GET', 'plans', 'Your hosting plans'],
    ['POST', 'accounts', 'New account: username, password, email, domain, plan, dns, mail'],
    ['POST', 'accounts/{username}/suspend', 'Suspend (websites off, no sign-in); reason'],
    ['POST', 'accounts/{username}/unsuspend', 'Start again'],
    ['POST', 'accounts/{username}/terminate', 'Remove the account with everything in it'],
    ['POST', 'accounts/{username}/password', 'New password'],
    ['POST', 'accounts/{username}/plan', 'Change the plan'],
    ['POST', 'accounts/{username}/sso', 'One-time sign-in link (60 seconds)'],
    ['GET', 'usage', 'Disk and traffic of every account'],
  ]
  return (
    <>
      <h1>Sell hosting</h1>
      <div className="card">
        <p>Sell shared hosting accounts on this server through your billing system: it creates, suspends, unsuspends and removes the accounts, changes plans and passwords, reads the usage and opens the panel for your customers with one click.</p>
        {err && <div className="alert bad">{err}</div>}
        <h3>1. Make your hosting plans</h3>
        <p className="muted small">Under Hosting plans: websites, databases, disk, traffic a month ... The billing system picks a plan by its name. <a href="#plans">Hosting plans</a></p>
        <h3>2. Make an API token</h3>
        <p className="muted small">{'The billing system signs in with it.'} {me.user.role === 'owner' ? 'It can manage every account of this server.' : 'It can manage only the accounts of your clients.'}</p>
        {token ? <div className="alert"><b>Copy it now, it is not shown again:</b> <span className="code" data-no-translate>{token}</span></div> : <button className="btn primary" onClick={make}>Make a token</button>}
        <h3>3. Add the module to your billing system</h3>
        <div className="sub-card">
          <h4>WHMCS</h4>
          <p className="muted small">Unzip into your WHMCS folder (it adds modules/servers/zarspanel and the converter addon). Setup &gt; Servers &gt; Add New Server: module "ZARS Panel", host name <span className="code" data-no-translate>{host}</span>, port <span className="code" data-no-translate>{port}</span>, the token in "Access Hash", SSL on. Products: Module Settings &gt; ZARS Panel &gt; your plan.</p>
          <p className="muted small">Coming from cPanel, Webuzo, DirectAdmin or Plesk: move the accounts with "Move from cPanel / Webuzo / DirectAdmin / Plesk", then Addons &gt; ZARS Panel converter switches the WHMCS services to this server.</p>
          <a className="btn" href="/api/billing-modules/whmcs">Download the WHMCS module</a>
        </div>
        <div className="sub-card">
          <h4>Blesta</h4>
          <p className="muted small">Unzip into your Blesta folder (components/modules/zarspanel). Settings &gt; Modules &gt; Available &gt; ZARS Panel &gt; Install, add this server with the token. Packages: the plan name.</p>
          <a className="btn" href="/api/billing-modules/blesta">Download the Blesta module</a>
        </div>
        <div className="sub-card">
          <h4>Your own system</h4>
          <p className="muted small">Any system that can call HTTPS: send the token as "Authorization: Bearer ..." to https://{host}:{port}/api/billing/...</p>
          <div className="wrap"><table className="table"><thead><tr><th>Call</th><th>Address</th><th>What it does</th></tr></thead><tbody>
            {calls.map(([m, p, t]) => <tr key={p + m}><td><span className="code" data-no-translate>{m}</span></td><td><span className="code" data-no-translate>/api/billing/{p}</span></td><td>{t}</td></tr>)}
          </tbody></table></div>
        </div>
      </div>
      <ClientMessages />
    </>
  )
}

// Webuzo "Email all users" and "News": tell every client something.
function ClientMessages() {
  const [m, setM] = useState({ subject: '', message: '' })
  const [news, setNews] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  useEffect(() => { api('/hosting-admin/news').then((r) => setNews(r.news ? r.news.text : '')).catch(() => {}) }, [])
  const done = (t) => { setMsg(t); setErr('') }
  return (
    <div className="card">
      <h2>Tell your clients</h2>
      {err && <div className="alert bad">{err}</div>}{msg && <div className="alert">{msg}</div>}
      <h3>News on their home page</h3>
      <form onSubmit={(e) => { e.preventDefault(); api('/hosting-admin/news', { text: news }).then(() => done(news ? 'The news is shown.' : 'The news is removed.')).catch((x) => setErr(x.message)) }}>
        <label>Text (empty: no news)<textarea rows="3" value={news} onChange={(e) => setNews(e.target.value)} placeholder="Maintenance on Sunday 2:00 - 3:00 ..." /></label>
        <button className="btn primary">Save</button>
      </form>
      <h3>Mail to every client</h3>
      <form onSubmit={(e) => { e.preventDefault(); window.confirm('Send this mail to every client with an e-mail address?') && api('/hosting-admin/mail-all', m).then((r) => { done('Sent to ' + r.sent + ' clients.'); setM({ subject: '', message: '' }) }).catch((x) => setErr(x.message)) }}>
        <label>Subject<input value={m.subject} onChange={(e) => setM({ ...m, subject: e.target.value })} required /></label>
        <label>{'Message (write {name} where the user name should go)'}<textarea rows="6" value={m.message} onChange={(e) => setM({ ...m, message: e.target.value })} required /></label>
        <button className="btn primary">Send</button>
      </form>
    </div>
  )
}
