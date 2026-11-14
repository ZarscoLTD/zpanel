import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.jsx'
import './app.css'

createRoot(document.getElementById('root')).render(<App />)

// Phones show tables as cards (app.css): every cell gets its column name, taken from the table's own header.
function labelTables(root) {
  root.querySelectorAll('table.table').forEach((t) => {
    const names = [...t.querySelectorAll('thead th')].map((th) => th.textContent.trim())
    if (!names.length) return
    t.querySelectorAll('tbody tr').forEach((tr) => {
      [...tr.children].forEach((td, i) => { if (names[i] && td.dataset.label !== names[i]) td.dataset.label = names[i] })
    })
  })
}
new MutationObserver(() => labelTables(document)).observe(document.body, { childList: true, subtree: true })
