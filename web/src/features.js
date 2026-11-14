// Every feature of the panel as a tile on the home page (grouped like a hosting control panel), with where it opens.
// to: '<page>' or '<page>/<tab>'. who: any = everybody, reseller = resellers and the owner, owner = the server owner only.
// site: true = it is a setting of one website (opens Websites; pick the site, its tab is named in the hint).
export const GROUPS = [
  ['clients', 'Accounts and plans', 'users'],
  ['web', 'Websites and domains', 'globe'],
  ['files', 'Files and backups', 'folder'],
  ['db', 'Databases', 'db'],
  ['mail', 'Email', 'mail'],
  ['security', 'Security', 'shield'],
  ['metrics', 'Statistics and logs', 'chart'],
  ['software', 'Software', 'code'],
  ['advanced', 'Advanced', 'terminal'],
  ['server', 'Server', 'server'],
  ['account', 'Account and help', 'user'],
]

// The menu (owner 2026-11-14: "nobody understands the panel"): twelve sections like aaPanel / CyberPanel, each a page of tool cards.
// [key, title, icon, colour, one line for the card, short menu name]; key = the group of FEATURES ('home' = the dashboard).
export const SECTIONS = [
  ['home', 'Dashboard', 'home', '#4f46e5', 'Your server at a glance', 'Dashboard'],
  ['web', 'Websites and domains', 'globe', '#2563eb', 'Sites, domains, SSL, DNS, one-click apps', 'Websites'],
  ['files', 'Files and backups', 'folder', '#0891b2', 'File manager, FTP, backups, cloud storage', 'Files'],
  ['db', 'Databases', 'db', '#7c3aed', 'MySQL / MariaDB, PostgreSQL, Redis, phpMyAdmin', 'Databases'],
  ['mail', 'Email', 'mail', '#db2777', 'Mailboxes, forwarders, webmail, spam, deliverability', 'Email'],
  ['security', 'Security', 'shield', '#dc2626', 'Firewall, malware scan, Fail2ban, two-step sign-in', 'Security'],
  ['software', 'Apps and software', 'store', '#ea580c', 'PHP, Node.js, Python, Docker, WordPress, installers', 'Apps'],
  ['metrics', 'Monitoring and logs', 'chart', '#16a34a', 'Live usage, graphs, visitors, logs', 'Monitoring'],
  ['advanced', 'Developer tools', 'terminal', '#0f766e', 'Cron jobs, terminal, services, templates', 'Developer'],
  ['clients', 'Accounts and reselling', 'users', '#9333ea', 'Create accounts, plans, transfers, billing', 'Accounts'],
  ['server', 'Server settings', 'server', '#475569', 'Host name, IPs, updates, certificates, alerts', 'Server'],
  ['account', 'My account', 'user', '#64748b', 'Password, two-step sign-in, support', 'My account'],
]
export const sectionOf = (key) => SECTIONS.find((x) => x[0] === key)

export const FEATURES = [
  // websites and domains
  ['web', 'Websites', 'Add and manage sites (PHP, static, proxy)', 'globe', 'sites', 'any'],
  ['web', 'Domains and aliases', 'Extra domains and subdomains of a site', 'globe', 'sites', 'any', 'Settings'],
  ['web', 'Change a domain', 'Give a website a new main domain (its folder moves with it)', 'globe', 'sites', 'any'],
  ['web', 'Redirects', 'Send one address to another', 'move', 'sites', 'any', 'Extras'],
  ['web', 'SSL / HTTPS', 'Free certificates, force HTTPS, renewals', 'lock', 'sites', 'any', 'SSL'],
  ['web', 'App store', 'One click: WordPress, Joomla, Drupal, Nextcloud, Dolibarr, OpenCart and 20 more', 'store', 'apps', 'any'],
  ['web', 'DNS zone editor', 'A, CNAME, MX, TXT records of your domains', 'dns', 'dns', 'any'],
  ['web', 'Cloudflare', 'Send the DNS records to Cloudflare', 'shield', 'cloudflare', 'any'],
  ['web', 'Uptime monitor', 'An alert when a website stops answering', 'chart', 'uptime', 'any'],
  ['web', 'Staging copy', 'Try changes on a copy, then push them live', 'globe', 'sites', 'any', 'Tools'],
  ['web', 'Preview address', 'Open a site before its domain points here', 'globe', 'sites', 'any', 'Tools'],
  // files and backups
  ['files', 'Disk usage', 'What takes the space of your account, largest first', 'disk', 'diskusage', 'any'],
  ['files', 'File manager', 'Browse, edit, upload, zip, permissions', 'folder', 'sites', 'any', 'Files'],
  ['files', 'FTP accounts', 'FTP / SFTP users locked into a site', 'upload', 'ftp', 'any'],
  ['files', 'Backups', 'Full and database backups, local or cloud, restore', 'archive', 'backups', 'any'],
  ['files', 'Git deployment', 'Deploy a site from a Git repository, with webhook', 'git', 'sites', 'any', 'Extras'],
  ['files', 'Password-protected folders', 'Ask for a password on a folder', 'lock', 'sites', 'any', 'Extras'],
  ['files', 'FTP sessions', 'Who is connected now, close a session', 'upload', 'tools/ftpx', 'owner'],
  ['files', 'Recycle bin', 'Deleted files wait 30 days', 'archive', 'sites', 'any', 'Files'],
  ['files', 'Share links', 'A download link for a file that ends by itself', 'upload', 'sites', 'any', 'Files'],
  ['files', 'Remote backup storage', 'Google Drive, Dropbox, OneDrive, SFTP, FTP, B2', 'archive', 'tools/rclone', 'owner'],
  // databases
  ['db', 'Databases', 'MariaDB / MySQL and PostgreSQL databases and users', 'db', 'databases', 'any'],
  ['db', 'phpMyAdmin', 'Work inside a database in the browser', 'db', 'tools/webtools', 'owner'],
  ['db', 'Redis', 'Redis caches with their own port and password', 'bolt', 'redis', 'any'],
  ['db', 'Running queries', 'See and stop slow database queries', 'list', 'tools/queries', 'owner'],
  ['db', 'Database server settings', 'MySQL / MariaDB settings and root password', 'cog', 'tools/svc', 'owner'],
  // email
  ['mail', 'Mailboxes', 'Email accounts with quotas', 'mail', 'mail', 'any'],
  ['mail', 'Forwarders and catch-all', 'Forward an address, default address of a domain', 'move', 'mail', 'any'],
  ['mail', 'Auto replies and filters', 'Out-of-office replies, move / forward / delete rules', 'list', 'mail', 'any'],
  ['mail', 'Deliverability', 'SPF, DKIM, DMARC and MX check of a domain', 'shield', 'mail', 'any'],
  ['mail', 'Webmail and calendars', 'Roundcube webmail, calendars and contacts', 'mail', 'tools/webtools', 'owner'],
  ['mail', 'Mail queue', 'Waiting mail: send now or delete', 'clock', 'tools/queue', 'owner'],
  ['mail', 'Track delivery', 'Sent, waiting and bounced mail per address', 'search', 'tools/maillog', 'owner'],
  ['mail', 'Mail settings', 'Relay, refuse senders, mail per domain', 'cog', 'tools/mailx', 'owner'],
  ['mail', 'Sending limits', 'Messages an hour per account and reseller', 'clock', 'tools/limits', 'owner'],
  // security
  ['security', 'Firewall', 'Open ports, allow and block addresses', 'shield', 'firewall', 'owner'],
  ['clients', 'Accounts resource usage', 'Disk, bandwidth and plan use of every account; change an account\'s owner', 'chart', 'usage', 'reseller'],
  ['metrics', 'Bandwidth', 'Data sent, requests and visitors per website and month', 'chart', 'bandwidth', 'any'],
  ['db', 'Remote MySQL', 'Computers outside the server that may connect to a database', 'db', 'remotedb', 'any'],
  ['security', 'IP blocker', 'Keep chosen IP addresses or ranges away from a website', 'shield', 'sites', 'any', 'Extras'],
  ['security', 'Server SSH settings', 'Root SSH keys and password sign-in of the server', 'key', 'tools/ssh', 'owner'],
  ['security', 'Two-step sign-in', 'Authenticator app codes for your account', 'lock', 'security', 'any'],
  ['security', 'API tokens', 'Tokens for scripts and other systems', 'key', 'security', 'any'],
  ['security', 'Hotlink and leech protection', 'Stop other sites using your files', 'shield', 'sites', 'any', 'Extras'],
  ['security', 'ModSecurity and malware scan', 'Web firewall rules, virus scan of a site', 'shield', 'sites', 'any', 'Extras'],
  ['security', 'Sign-in log', 'Every sign-in to the panel', 'list', 'tools/logins', 'owner'],
  ['security', 'Activity log', 'Every change made in the panel', 'list', 'settings/audit', 'owner'],
  ['security', 'Advisor', 'A score with what to fix', 'shield', 'tools/advisor', 'owner'],
  ['security', 'Fail2ban', 'Blocked addresses, unblock, rules', 'shield', 'tools/jails', 'owner'],
  ['security', 'Block countries', 'Drop connections from whole countries', 'shield', 'tools/countries', 'owner'],
  ['security', 'Panel access', 'Allowed addresses, sign-in mails, session length', 'lock', 'tools/paccess', 'owner'],
  ['security', 'Malware scanner (Imunify)', 'ImunifyAV free, Imunify360 with a licence', 'shield', 'tools/imunify', 'owner'],
  ['security', 'Passwords and processes', 'Password strength, fork bomb protection', 'lock', 'tools/plimits', 'owner'],
  ['security', 'Block bad bots', 'Refuse scrapers and attack tools', 'shield', 'sites', 'any', 'Tools'],
  // statistics and logs
  ['metrics', 'Server status', 'CPU, memory, disk, network, services', 'cpu', 'overview', 'any'],
  ['metrics', 'Visitor statistics', 'Visitors, pages, countries of a site', 'chart', 'sites', 'any', 'Extras'],
  ['metrics', 'Access and error logs', 'The web logs of a site', 'list', 'sites', 'any', 'Logs'],
  ['metrics', 'Processes and resource use', 'What runs, CPU and memory per user', 'cpu', 'tools/processes', 'owner'],
  ['metrics', 'History graphs', 'CPU, memory, disk and network over 30 days', 'chart', 'tools/history', 'owner'],
  // software
  ['software', 'PHP version and settings', 'PHP version and php.ini values of a site', 'code', 'sites', 'any', 'Settings'],
  ['software', 'Node.js and Python apps', 'Keep your app running behind a site', 'code', 'sites', 'any', 'Extras'],
  ['software', 'Optimize website', 'Compression and browser cache', 'bolt', 'sites', 'any', 'Extras'],
  ['software', 'PHP extensions', 'Install and switch PHP extensions', 'plug', 'tools/php', 'owner'],
  ['software', 'Server software', '43 programs: web servers, languages, databases', 'box', 'tools/software', 'owner'],
  ['software', 'Perl and PEAR modules', 'Install modules for scripts', 'box', 'tools/modules', 'owner'],
  ['software', 'Docker', 'Containers, logs and templates', 'docker', 'docker', 'owner'],
  ['software', 'WordPress manager', 'Updates, plugins, themes, maintenance, safer settings', 'code', 'sites', 'any', 'Tools'],
  ['software', 'Laravel tools', 'Artisan commands and the scheduler', 'code', 'sites', 'any', 'Tools'],
  ['software', 'Background programs', 'Queue workers and scripts that keep running', 'cpu', 'sites', 'any', 'Tools'],
  ['software', 'Page cache', 'Faster PHP sites, cleared with one click', 'bolt', 'sites', 'any', 'Tools'],
  ['software', 'OpenLiteSpeed and LSCache', 'LiteSpeed web server for a PHP site, with LSCache for WordPress', 'bolt', 'sites', 'any', 'Tools'],
  // advanced
  ['advanced', 'Cron jobs', 'Run commands, URLs or backups on a schedule', 'clock', 'cron', 'any'],
  ['advanced', 'Terminal', 'Run a command on the server', 'terminal', 'terminal', 'owner'],
  ['advanced', 'Error pages, indexes, MIME types', 'Own 404 / 500 pages and folder settings', 'code', 'sites', 'any', 'Extras'],
  ['advanced', 'Server network check', 'Ping, trace, DNS lookup, port check from the server', 'network', 'tools/network', 'owner'],
  ['advanced', 'Process rules', 'Programs always stopped, limits', 'cpu', 'tools/procx', 'owner'],
  ['advanced', 'PHP-FPM, MySQL, Apache', 'Service settings with automatic fallback', 'cog', 'tools/svc', 'owner'],
  ['advanced', 'Templates and logs', 'Templates of new sites, server logs', 'code', 'tools/templates', 'owner'],
  ['advanced', 'System services', 'Start, stop and start-up of every service', 'cog', 'tools/services', 'owner'],
  ['advanced', 'System logs', 'The system journal, per service', 'list', 'tools/journal', 'owner'],
  // server
  ['server', 'Server, host name and time zone', 'Host name, resolvers, reboot', 'server', 'tools/server', 'owner'],
  ['server', 'Panel and mail certificate', 'Free certificate for the host name, used by the panel and mail', 'server', 'tools/server', 'owner'],
  ['server', 'System updates', 'Install operating system updates', 'refresh', 'tools/updates', 'owner'],
  ['server', 'IP addresses', 'The server addresses and shared IP', 'network', 'tools/ips', 'owner'],
  ['server', 'IPs, disks and sites', 'Change the IP everywhere, move a site, site owner', 'disk', 'tools/move', 'owner'],
  ['server', 'Users and access', 'Sudo administrators, shell access', 'users', 'tools/access', 'owner'],
  ['server', 'Other servers', 'Manage more servers from here', 'server', 'tools/remote', 'owner'],
  ['server', 'Export and clean-up', 'Settings to another server, unused zones', 'archive', 'tools/export', 'owner'],
  ['server', 'Plugins', 'Add-ons for the panel', 'plug', 'tools/plugins', 'owner'],
  ['server', 'Brand and look', 'Panel name, logo, colour', 'tag', 'settings/brand', 'owner'],
  ['server', 'Backup settings', 'Where and how often backups go', 'archive', 'settings/backups', 'owner'],
  ['server', 'Alerts', 'Mail, webhook or Telegram when load, disk or a service is wrong', 'bolt', 'settings/alerts', 'owner'],
  ['server', 'Panel updates', 'Automatic updates of the panel', 'refresh', 'settings/updates', 'owner'],
  ['server', 'Time and swap', 'Time zone, clock sync, swap file', 'clock', 'tools/time', 'owner'],
  ['server', 'DNS cluster', 'Other DNS servers answer for your zones', 'dns', 'tools/cluster', 'owner'],
  // clients
  ['clients', 'Create an account', 'Login, hosting plan and main website in one step (like WHM)', 'users', 'users', 'reseller'],
  ['clients', 'List accounts', 'Clients and resellers, limits, suspend, delete, sign in as', 'users', 'users', 'reseller'],
  ['clients', 'Users already on this server', 'Make a Linux user of this server a panel account', 'user', 'users', 'owner'],
  ['clients', 'Hosting plans', 'Plans with limits given in one step', 'tag', 'plans', 'reseller'],
  ['clients', 'Move from cPanel / Webuzo / DirectAdmin / Plesk', 'Bring sites, databases and mail across', 'move', 'import', 'reseller'],
  ['clients', 'Sign in as a client', 'Open the panel as a client to help them', 'users', 'users', 'reseller'],
  ['clients', 'Move from another server', 'Any server with SSH: files and database', 'move', 'migrate', 'any'],
  ['clients', 'Sell hosting (WHMCS, Blesta)', 'Billing systems create, suspend and remove accounts', 'store', 'billing', 'reseller'],
  ['clients', 'Tell your clients', 'News on their home page, a mail to all', 'mail', 'billing', 'reseller'],
  ['clients', 'Plan features', 'What clients of a plan may use', 'tag', 'plans', 'reseller'],
  ['clients', 'Delete an account', 'A client with all websites, mail and data', 'users', 'users', 'reseller'],
  // features that had no card before (2026-11-14 review: "free panels give more" - they were there, just not visible)
  ['clients', 'Mass changes', 'Plan, limits or suspend for many accounts at once', 'users', 'plans', 'reseller'],
  ['clients', 'Your brand', 'Your name, logo and colour for your clients', 'tag', 'branding', 'reseller'],
  ['web', 'DNSSEC', 'Sign a DNS zone so nobody can fake its answers', 'dns', 'dns', 'any'],
  ['web', 'Dynamic DNS', 'A record that follows a changing IP (office, home)', 'dns', 'dns', 'any'],
  ['web', 'Wildcard SSL', 'One certificate for *.example.com (DNS challenge)', 'lock', 'sites', 'any', 'SSL'],
  ['web', 'DNS template', 'The records every new zone starts with', 'dns', 'tools/templates', 'owner'],
  ['mail', 'Mailing lists', 'One address that sends to all members', 'users', 'mail', 'any'],
  ['mail', 'Spam filter', 'Score from which mail goes to Junk', 'shield', 'mail', 'any'],
  ['mail', 'Import mailboxes', 'Many mailboxes at once from a list', 'upload', 'mail', 'any'],
  ['mail', 'MTA-STS', 'Other mail servers must use encryption to reach you', 'lock', 'mail', 'any'],
  ['mail', 'Greylisting', 'Unknown senders try again later - stops most spam', 'shield', 'tools/templates', 'owner'],
  ['mail', 'Reverse DNS check', 'The PTR of the server address, needed for mail', 'mail', 'tools/ips', 'owner'],
  ['security', 'Company sign-in (SSO)', 'Google Workspace, Microsoft or any OpenID sign-in for the panel', 'lock', 'tools/paccess', 'owner'],
  ['db', 'Adminer (PostgreSQL)', 'PostgreSQL and MySQL in the browser', 'db', 'tools/webtools', 'owner'],
  ['advanced', 'Log rotation', 'How long web logs are kept', 'list', 'tools/templates', 'owner'],
  ['server', 'Certificate authority', "Let's Encrypt or ZeroSSL for free certificates", 'lock', 'tools/server', 'owner'],
  ['server', 'Brotli compression', 'Smaller pages for every website', 'bolt', 'tools/server', 'owner'],
  ['server', 'Moved to a new IP', 'Change the server address in every DNS zone', 'network', 'tools/ips', 'owner'],
  // account
  ['account', 'My account', 'Password, two-step sign-in, tokens', 'user', 'security', 'any'],
  ['account', 'Support tickets', 'Ask for help, see answers', 'ticket', 'tickets', 'any'],
  ['security', 'My SSH keys', 'Sign in to the server with your own SSH key', 'key', 'sshkeys', 'any'],
  ['advanced', 'Network tools', 'DNS lookup, ping and trace route', 'network', 'nettools', 'any'],
].map(([group, name, text, icon, to, who, siteTab]) => ({ group, name, text, icon, to: siteTab ? 'tool/' + toolSlug(name) : to, page: siteTab ? 'sites' : to.split('/')[0], who, siteTab }))

/** A website tool's own page address (tool/<slug>), like cPanel's one page per tool. */
export function toolSlug(name) { return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') }

export const canSee = (who, role) => who === 'any' || (who === 'owner' && role === 'owner') || (who === 'reseller' && role !== 'client')

// pages that belong to a plan feature (Hosting plans > features): hidden when the client's plan switches it off
export const PAGE_FEATURE = { dns: 'dns', cloudflare: 'dns', mail: 'email', databases: 'databases', ftp: 'ftp', cron: 'cron', apps: 'apps', backups: 'backups', redis: 'redis', uptime: 'uptime', migrate: 'migrate', import: 'migrate' }
export const TAB_FEATURE = { Files: 'files', SSL: 'ssl', Tools: 'developer', Logs: 'statistics' }
export const featureOff = (me, page) => {
  if (me.edition === 'community' && !(me.community_pages || []).includes(page)) return true // Community edition: only the basic pages
  const off = (me.user.quota && me.user.quota.off) || []; return me.user.role !== 'owner' && off.includes(PAGE_FEATURE[page])
}
