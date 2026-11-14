# ZARS Panel 2.0 - rebuild from scratch (owner 2026-11-14)

Goal: every function of every hosting control panel in use today (cPanel + WHM, Webuzo, Plesk, DirectAdmin, CyberPanel, aaPanel,
HestiaCP, CloudPanel, ISPConfig, Virtualmin), plus every feature already in Panel 1.x, plus extras. Same features and flows, our own
code, names and look. The Webuzo copy in ~/zarsco/projects/webuzo is read ONLY to list features and understand flows (its page list:
169 admin pages, 82 end-user pages) - nothing of its code, text, names or design is used.

## Structure: three panels, like WHM + cPanel

| Panel | Who | Like | Address |
|---|---|---|---|
| Server admin | owner (root) | WHM, Webuzo Admin, Plesk admin, DirectAdmin admin | https://server:2087-style port, /admin |
| Reseller | resellers | WHM reseller, DirectAdmin reseller | /reseller (only their accounts, packages, branding) |
| Hosting account | each customer | cPanel, Webuzo Enduser, DirectAdmin user | https://server:2083-style port, /user |

Each panel has its own home (search box + feature tiles grouped by area), its own sidebar and its own colours; "Sign in as" moves down a
level and back. Every account is a Linux user (already in 1.x: SysUser), every action is checked against the owner chain (1.x rules
kept: Exec is the only door to the system, dry-run mode, rollback of refused nginx / sshd changes, sealed secrets, signed updates).

## Server admin (WHM / Webuzo Admin class)

- Accounts: create account (user, password, domain, package, IP, shell, language), list / search / mass modify, suspend / unsuspend,
  terminate, change domain, change owner, change IP, password, quota, bandwidth limits and reset, upgrade / downgrade package, skeleton
  directory, rearrange accounts between disks, account resource usage, login as, user logs.
- Packages and feature sets: plans with limits (disk, bandwidth, domains, subdomains, parked / addon domains, mail accounts, databases,
  FTP, cron, inodes, processes, CPU / RAM / IO), feature sets (which tools a plan may use).
- Resellers: create, privileges (ACL), resource limits, accounts per reseller, IP delegation, branding / rebranding, reseller mail.
- Domains and DNS: domains, park / addon / subdomains, redirects, forwarding, DNS zones and template, advanced DNS, MX entry, set TTL,
  clean-up, nameservers, resolver, hostname, cluster / slave DNS, Cloudflare.
- Server: information, stats, load, CPU, RAM, disk, processes, process manager and killer, daily process log, services manager and
  restart, service TLS, reboot, system update, distro packages, RPM / deb database, trace route, terminal, root password, root SSH keys,
  hostname, time zone, bandwidth view, current disk usage.
- Web server and PHP: Apache / Nginx / LiteSpeed / OpenLiteSpeed choice, settings and templates (template editor), Varnish, PHP versions
  (MultiPHP manager), php.ini editor, extensions, PHP-FPM pools, PEAR / Perl modules, compilers access, directory index, error logs.
- Databases: MySQL / MariaDB settings, root password, manage databases and users, change user password, additional access hosts,
  repair, process list, DB map tool, PostgreSQL, MongoDB, Redis, phpMyAdmin / pgAdmin.
- Mail: mail settings, queue manager, greylisting, relayers, SMTP restrictions, deliverability (SPF / DKIM / DMARC / PTR), delivery
  report, sent summary, troubleshooter, router config, domain and country filters, spam (SpamAssassin / Rspamd) start-up, disk usage,
  mail all users, e-mail templates, system mail.
- Security: firewall, IP block, brute-force protection and logs, ModSecurity (config, rules, vendors, tools), two-factor, password
  strength, shell access manager, shell fork bomb protection, SSH settings and keys, compiler access, blacklisted domains, malware scan.
- Backups: backup servers (local, SSH, FTP, S3, Google Drive, Dropbox), schedules, settings, restore, transfer / import from cPanel,
  Webuzo, Plesk, DirectAdmin, CyberPanel, another server; IP migration.
- Apps: one-click apps (WordPress and a large catalogue), installations, updates, app categories; server software catalogue.
- SSL: automatic SSL for all accounts, Let's Encrypt / ZeroSSL, wildcard by DNS, install certificate, service certificates.
- Logs and monitoring: login logs, user logs, error logs, log rotation, uptime monitor, alerts (mail / webhook / Telegram), news and
  notices to users, support tickets, tasks / background jobs.
- Settings: panel settings, ports, update settings, licence, API keys, single sign-on, branding, languages, plugins, webuzo-style config.

## Hosting account (cPanel / Webuzo Enduser class)

- Domains: add / edit / manage domains, subdomains, aliases, redirects, DNS (advanced DNS, MX), error pages, MIME types, hotlink
  protection, password-protected folders, extra web-server config.
- Files: file manager, FTP accounts and connections, disk usage, directory privacy, backups (download, restore, remote backups).
- Databases: MySQL databases and users (wizard), phpMyAdmin, remote MySQL access, PostgreSQL.
- Mail: accounts, forwarders, default address, autoresponders, filters, mail routing, deliverability, track delivery, queue, sent
  summary, relayers, webmail, address importer, spam settings, encryption keys.
- Software: one-click apps, PHP version per site (MultiPHP), php.ini editor, PEAR modules, Node.js / Python / Ruby apps, Tomcat,
  Composer, Git deployment, cron jobs.
- Security: SSL (Let's Encrypt, CSR / key / certificate), ModSecurity per domain, IP blocker, SSH access and keys, two-factor, login logs.
- Metrics: bandwidth, visitors, AWStats, error log, resource usage.
- Account: change password, settings, language, API key, single sign-on, network tools, terminal (when allowed), help.

## Extras beyond the other panels (from Panel 1.x and new)

WHMCS / Blesta modules, staging copies, preview addresses, uptime monitor, Docker, cloud backups with retention, signed staged updates,
owner IP allow-list, company sign-in (OIDC), activity log CSV, server users adopted as accounts, licence and community edition,
Hindi / Marathi / Bengali / Tamil, dark mode, global search, mobile-friendly screens.

## Order of work

1. Data model and API for accounts, packages, feature sets, resellers (keep the 1.x safety layer) + the three panel shells.
2. Hosting account panel (cPanel class) - every page above, in groups: domains, files, databases, mail, software, security, metrics.
3. Server admin (WHM class) - accounts and packages first, then server, web / PHP, databases, mail, security, backups, settings.
4. Reseller panel.
5. Importers (cPanel, Webuzo, Plesk, DirectAdmin backups), migration, extras.
Each group: tests in tests/run.php, real-browser screenshots, a release (2.0.0 when the three panels and accounts are done).

## Progress
- Part 1 (released in 1.2.0): the three panel shells - Server admin home with every function by category, Reseller center, Hosting
  account home (tool groups, general information, statistics against the plan).
- Part 2a: Server admin / reseller sidebar = the full function tree like WHM (search, folding categories).
- Part 2b: every website tool has its own page like cPanel (tool/<name>: domain picker on top, only that tool below) instead of
  "open Websites, pick the site, open the tab".
- Part 2c: hosting account pages Network tools (DNS lookup, ping, trace route), SSH access (public keys of the account's Linux user,
  private keys refused) and Disk usage (per website and mail, against the plan).
- Part 2d: Remote MySQL (extra hosts per database user), IP blocker tile (per-website deny list on its own page), Bandwidth per
  website and month (from the access logs).
- Part 3a/3b + 4a (released in 1.3.0 / 1.4.0): accounts resource usage, change account owner, server time zone, reseller limits
  (accounts allowed, no overselling to clients or plans), one-time auto-login link per account, limits window like WHM.
- Done since 1.4.0: change an account's main domain, service TLS (panel / mail certificates), reseller's own brand page (Accounts >
  Your brand), DirectAdmin and Plesk backup importers (next to cPanel, Webuzo and the SSH move).
- Part 6: ZeroSSL as certificate authority, wildcard certificates by the DNS challenge, reverse DNS (PTR) check, server IP change across
  all zones, files and folders (inodes) in disk usage and plan limits, Adminer for PostgreSQL.
- Part 7: DNSSEC (BIND inline signing, DS record for the registrar), MTA-STS + TLS reports for mail domains, Brotli compression,
  Telegram alerts.
- Released as 2.0.0. Checked feature by feature against this plan (two keyword passes over src/). 2.2.0 adds OpenLiteSpeed (owner
  "litespeed yes"): per PHP website, LSAPI PHP + .htaccess + LSCache, behind Nginx so every site tool keeps working. LiteSpeed
  Enterprise (paid licence) is not included.

