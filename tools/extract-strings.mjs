// Lists the English texts of the panel UI (JSX text, labels, placeholders, titles, button texts, tile and menu texts),
// so tools/lang/<code>.json can be checked for missing translations: node tools/extract-strings.mjs [--missing <code>]
import { readFileSync, readdirSync, existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const src = path.join(root, 'web/src')
const found = new Set()
const add = (s) => {
  s = s.replace(/\s+/g, ' ').trim().replace(/&gt;/g, '>').replace(/&lt;/g, '<').replace(/&amp;/g, '&') // as the page shows it
  if (s.length < 2 || !/[A-Za-z]{2}/.test(s) || /^[a-z0-9_./:-]+$/.test(s)) return
  if (/^M[0-9]/.test(s)) return // svg path
  if (/[{}<>=]|=>|&&|\|\||\?\?|\breturn\b|\bif \(|setErr|setMsg|\)\)/.test(s)) return // code, not text
  found.add(s)
}

for (const file of readdirSync(src).filter((f) => /\.(jsx|js)$/.test(f) && f !== 'i18n.js')) {
  const code = readFileSync(path.join(src, file), 'utf8')
  // JSX text between tags
  for (const m of code.matchAll(/>([^<>{}]*[A-Za-z][^<>{}]*)[<{]/g)) add(m[1]) // JSX text, also before {expressions}
  // user-facing attributes
  for (const m of code.matchAll(/\b(?:placeholder|title|aria-label|label|alt)=["']([^"']+)["']/g)) add(m[1])
  // every quoted string, read left to right; texts start with a capital letter (class names and keys do not)
  for (const m of code.matchAll(/'((?:[^'\\\n]|\\.)*)'|"((?:[^"\\\n]|\\.)*)"/g)) {
    const str = (m[1] ?? m[2]).replace(/\\'/g, "'")
    if (/^[A-Z][a-z]/.test(str) || /^[A-Z][A-Z0-9.]*[ ,a-z]/.test(str) || /^[0-9]+ [a-z]/.test(str)) add(str)
  }
}

const list = [...found].sort()
const i = process.argv.indexOf('--missing')
if (i !== -1) {
  const file = path.join(root, 'web/src/lang', process.argv[i + 1] + '.json')
  const have = existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : {}
  const missing = list.filter((s) => !(s in have))
  console.log(JSON.stringify(missing, null, 1))
  console.error(missing.length + ' missing of ' + list.length)
} else {
  console.log(JSON.stringify(list, null, 1))
  console.error(list.length + ' texts')
}
