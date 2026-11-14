// Panel languages (cPanel "Change language"). The English text is the key (the gettext pattern): web/src/lang/<code>.json
// maps each English text of the panel to the language. One translator watches the page and shows every known text,
// placeholder, title and screen-reader label in the chosen language; a text without a translation stays English.
// List what a language still misses: node tools/extract-strings.mjs --missing hi

const loaders = {
  hi: () => import('./lang/hi.json'),
  mr: () => import('./lang/mr.json'),
  bn: () => import('./lang/bn.json'),
  ta: () => import('./lang/ta.json'),
}
export const LANGUAGES = { en: 'English', hi: 'हिन्दी', mr: 'मराठी', bn: 'বাংলা', ta: 'தமிழ்' }

let dict = null
let current = 'en'
const ATTRS = ['placeholder', 'title', 'aria-label', 'alt']
const textSeen = new WeakMap() // text node -> { en, shown }
const attrSeen = new WeakMap() // element -> { attr: { en, shown } }
const SKIP = 'script,style,code,pre,textarea,[data-no-translate],.terminal-out,.file-name'

export const t = (s) => (dict && dict[s]) || s

function lookup(text) {
  const core = text.trim()
  if (!core || !dict) return null
  if (dict[core]) return text.replace(core, dict[core])
  // the same text with a colon or dots after it
  const m = core.match(/^(.*?)(\s*[:.]{1,3})$/)
  if (m && dict[m[1]]) return text.replace(core, dict[m[1]] + m[2])
  return null
}

function translateText(node) {
  const seen = textSeen.get(node)
  const value = node.nodeValue
  if (seen && value === seen.shown) return
  const en = value
  const shown = dict ? lookup(en) : null
  if (shown && shown !== value) {
    textSeen.set(node, { en, shown })
    node.nodeValue = shown
  } else if (seen) {
    textSeen.delete(node)
  }
}

function translateAttrs(el) {
  let seen = attrSeen.get(el)
  for (const a of ATTRS) {
    if (!el.hasAttribute(a)) continue
    const value = el.getAttribute(a)
    const prev = seen && seen[a]
    if (prev && value === prev.shown) continue
    const shown = dict ? lookup(value) : null
    if (shown && shown !== value) {
      if (!seen) { seen = {}; attrSeen.set(el, seen) }
      seen[a] = { en: value, shown }
      el.setAttribute(a, shown)
    } else if (prev) {
      delete seen[a]
    }
  }
}

function walk(root) {
  if (root.nodeType === 3) {
    if (!root.parentElement || !root.parentElement.closest(SKIP)) translateText(root)
    return
  }
  if (root.nodeType !== 1) return
  if (root.closest(SKIP)) { translateAttrs(root); return }
  translateAttrs(root)
  const it = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
    // a skipped element (textarea, code ...) keeps its text but its placeholder / title are still translated
    acceptNode: (n) => { if (n.nodeType === 1 && n.matches(SKIP)) { translateAttrs(n); return NodeFilter.FILTER_REJECT } return NodeFilter.FILTER_ACCEPT },
  })
  for (let n = it.nextNode(); n; n = it.nextNode()) {
    if (n.nodeType === 3) translateText(n)
    else translateAttrs(n)
  }
}

// back to English: every text and attribute this translator changed
function restore(root) {
  const it = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT)
  for (let n = it.currentNode; n; n = it.nextNode()) {
    if (n.nodeType === 3) {
      const seen = textSeen.get(n)
      if (seen && n.nodeValue === seen.shown) n.nodeValue = seen.en
      textSeen.delete(n)
    } else if (n.nodeType === 1) {
      const seen = attrSeen.get(n)
      if (seen) for (const a of Object.keys(seen)) if (n.getAttribute(a) === seen[a].shown) n.setAttribute(a, seen[a].en)
      attrSeen.delete(n)
    }
  }
}

let observer = null
function watch() {
  if (observer) return
  observer = new MutationObserver((list) => {
    if (!dict) return
    for (const m of list) {
      if (m.type === 'characterData') walk(m.target)
      else if (m.type === 'attributes') translateAttrs(m.target)
      else m.addedNodes.forEach(walk)
    }
  })
  observer.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTRS })
}

export const language = () => current

export async function setLanguage(code) {
  if (!LANGUAGES[code]) code = 'en'
  try { localStorage.setItem('zp-lang', code) } catch (e) { /* private window */ }
  if (code === current) return
  const next = code === 'en' ? null : ((await loaders[code]()).default || {})
  if (dict) restore(document.body)
  dict = next
  current = code
  document.documentElement.lang = code
  watch()
  if (dict) walk(document.body)
}

// the language this browser used last (sign-in page, before the account's own language is known)
export function savedLanguage() {
  try { return localStorage.getItem('zp-lang') || 'en' } catch (e) { return 'en' }
}
