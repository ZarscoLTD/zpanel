# Control panel feature map (study notes, own words)

Not a task list: open tasks of every project live only in ~/zarsco/OPEN_TASKS.md (Claude) and ~/zarsco/NEEDS_OWNER_HELP.md (owner).

Studied on 2026-10-29: the route table and the front-end of the panel installed on the owner's machine (aaPanel, Flask + jQuery, about 100 routes) and the Softaculous / Webuzo model (an app installer on top of a hosting panel). Nothing of their code, names or text is used in ZARS Panel.

| Area | What they offer | ZARS Panel v1 |
|---|---|---|
| Overview | CPU, RAM, disk, load, network, running services, installed software | Yes: live numbers from /proc, services with start / stop / restart |
| Websites | Add site (PHP / static / reverse proxy), domains, redirects, rewrite presets, per-site PHP version, logs, backups, WAF | Yes: PHP / static / proxy sites, aliases, redirects, rewrite presets, per-site logs, per-site PHP version, security headers |
| SSL | Let's Encrypt (HTTP and DNS), wildcard, force HTTPS, renewals | Yes: Let's Encrypt via certbot, force HTTPS, auto renewal check |
| Databases | MySQL / MariaDB, PostgreSQL, Mongo, Redis, phpMyAdmin | MariaDB / MySQL and PostgreSQL: create, users, backup, import; Redis (0.2.0) and MongoDB (Software tab, 0.6.0) built |
| FTP | Pure-FTPd accounts | SFTP users per site (jailed); FTP built in 0.2.0 |
| Files | Browser file manager, edit, upload, zip, permissions | Yes: jailed to the web root, edit, upload, zip, chmod |
| Terminal | Web SSH | Built in 0.2.0 (owner only, audited) |
| Cron | Shell, URL, backup, log-cut jobs | Yes: shell, URL, site backup, database backup |
| Firewall | Port rules, IP allow / deny, SSH settings, fail2ban-like | Yes: ufw rules, IP block, SSH port and root-login switch, fail2ban status |
| Backups | Local and cloud (S3, Drive and others) | Yes: site + database, local, S3-compatible, retention, restore |
| App store | Software installer plus one-click apps | Yes: WordPress, plain website, your own Laravel app from a zip (ZARSCO products are not part of the panel) |
| Docker | Container manager | Built in 0.2.0 |
| Users | Single admin, API key | Yes: owner, resellers and clients with quotas, API tokens, two-step login |
| Alerts | Mail / push on load, disk, service down | Yes: mail and webhook alerts |
| Mail | Postfix + Dovecot + Rspamd plug-in | Server mail built in 0.2.0 (Postfix + Dovecot); not tied to any ZARSCO product |
| Updates | Panel and software updates | Yes: update channel (signed release feed), one-click panel update |
| White label | Not offered | Yes: panel name, logo, colours, domain per reseller |

## 0.2.0 (2026-10-30): server features of the Webuzo class
DNS zone editor (BIND zones, checked with named-checkzone before loading), FTP users (Pure-FTPd, locked into a site folder), Redis instances (own port, password and memory limit),
Docker (containers, logs, one-click templates; owner only), server mail (Postfix + Dovecot virtual domains, mailboxes with hashed passwords and quotas, forwards, DKIM / SPF / DMARC hints),
web terminal (owner only, one command at a time, audited, needs two-step sign-in), ClamAV scan of a site, staged rollout of updates (percent of servers per release) and a per-server version pin.

## 0.3.0 (2026-11-02): screen-by-screen check against the Webuzo code the owner gave (~/zarsco/projects/webuzo, own code only)
Closed now: hosting plans (feature sets / plans) given to clients in one step; per-site error pages, hotlink protection, password-protected folders, blocked IPs, folder listing, PHP settings (multi-PHP INI editor), extra MIME types, disk and traffic per site (disk usage / bandwidth / visitors); own SSL certificates (key + CSR, install the authority's certificate, checked against the key and the domain); database remote access hosts, check / repair / optimize / analyze, running queries with stop; mail out-of-office replies (Sieve), the mail queue (flush, delete), deliverability check (MX, SPF, DKIM, DMARC); server tools: processes with stop, host name, DNS resolvers, reboot, system package updates (security only or all), ping / trace route / DNS / MX / whois, PHP extensions install / remove, SSH keys (add, make a pair, remove, password sign-in on / off with lock-out guard), sign-in log.

## 0.4.0 (2026-11-03): the rest of the Webuzo list, working end to end
cPanel full-backup import (main + addon domains with their files, MySQL databases with new passwords, mailboxes with their old password hashes and their mail), DNS template for new zones, skeleton folder for new sites, ModSecurity (OWASP rules) and a speed limit per site, mail filters (Sieve, together with the out-of-office reply; delivery through Dovecot LMTP), greylisting (postgrey), log rotation, monthly bandwidth limits per user (sites paused hourly when passed, started again in the new month), extra IPv4 / IPv6 addresses (live + netplan), software installer (Tomcat, Java, Varnish, Node.js, Composer, Supervisor, MongoDB ... and Perl / PEAR modules), changes to many users at once (suspend, start, plan, PHP version), other ZARS Panel servers with live health (sealed API tokens), support tickets (client to reseller, reseller to owner).

Not taken over on purpose: Apache (the panel serves with nginx; Varnish can be installed), Webuzo's own backup format (cPanel backups are the common source), the Softaculous script catalogue (our App store installs WordPress, plain sites and Laravel apps).

## 0.5.0 (2026-11-03): cPanel parity
Files (manager, privacy, disk use, FTP, backups + single-file restore, Git deploy with webhook), databases (MariaDB, PostgreSQL, remote access, phpMyAdmin with a 2-hour per-database sign-in), domains (sites, aliases, redirects, zone editor, dynamic DNS), email (accounts, forwarders, catch-all, autoresponders, filters, mailing lists, CSV address importer, spam filter, greylisting, DKIM / SPF / DMARC check, queue, disk use, Roundcube webmail, CalDAV / CardDAV), metrics (visitor statistics with GoAccess, traffic, error and access logs), security (SSH keys, IP blocker, SSL / own certificates, API tokens, hotlink and leech protection, ModSecurity, two-step sign-in), software (PHP extensions, PEAR / Perl modules, MultiPHP and INI settings, App store, Node.js and Python apps), advanced (terminal, cron, network tools, folder listing, error pages, MIME types, virus scan), users (plans, resellers, tickets, mass changes, cPanel import).

## 0.14.0 (2026-11-11): licence
Paid licence per server (prices on panel.zarsx.in, cheaper than every other panel): a new panel runs a 15-day free trial; Settings >
Licence (or `zp licence <key>`) takes a ZP-XXXX-XXXX-XXXX-XXXX key from ZARS Insights (Control center > Panel licences). The panel asks
the licence server every night (`zp licence-check`); the answer is signed and trusted for about a week, so an outage changes nothing.
Without a valid licence the websites, mail and databases keep running and only changes from the panel stop (read-only). Starter
allows 10 websites. The Community edition needs no licence. Demo login password: Demo@zarsx.

## 0.13.4 (2026-11-11): ZARS offers in the panel
- The offer running on ZARS products (ZARS Insights > Control center > Offers, owner: every offer also applies to ZARS Panel) shows on
  the Home page for the server owner, with what it means for a Panel licence; "Get it free" opens the licence request form on the
  Panel website. Read from the same Insights server as the updates, remembered 6 hours (never slows the page). Community edition too.

## 0.13.3 (2026-11-10): demo login, Community edition only the basics
- `zp demo-account`: a client login called "demo" that can open every screen a client sees and changes nothing (every write is
  refused on the server, a "Demo" bar on every screen). It owns no sites, so no server config is written for it. Each run gives it a new
  password and mails the login to the alert address (Settings > Alerts). Part of the demo world for showing customers.
- The full ZARS Panel is sold (licence per server). The free Community edition keeps only the basic needs of one server (websites,
  SSL, files, databases, scheduled jobs, backups, firewall, server status, two-step sign-in): src/Edition.php refuses the rest, and
  bin/make-community.sh leaves the paid features' source out, smoke-checks the result (tools/community-smoke.php) and the Community
  server never installs the full panel's updates.

## 0.13.2 (2026-11-09): status pills
Status pills (active, on, none ...) never break across two lines in narrow table columns. Its website: panel.<domain> (served by
ZARS Insights) with the one-line install `curl -fsSL https://panel.zarsx.in/install.sh | sudo bash`, which downloads this signed,
protected package from the update server and checks the checksum and the signature before running install/install.sh.

## 0.13.1 (2026-11-09): the package carries no readable code
Owner rule: whoever installs ZARS Panel never gets its code. The release package now holds src/autoload.php (a small loader) and
src/panel.bin (every class and the zp command, comments stripped, compressed and encrypted with a key made for each build); the code
runs only in memory. bin/zp is a stub; the maintainer tools (release builder, community script) are left out. With IONCUBE_ENCODER
set (once the encoder is bought) tools/protect.php encodes with ionCube instead (stronger; servers need the free ionCube Loader).

## 0.13.0 (2026-11-09): one Linux user per hosting account
What cPanel, DirectAdmin and CloudLinux do for shared hosting security: before, every site ran as www-data, so one hacked site
could read the files of every other account.
- Each account gets its own Linux user (zpu + account number, no login shell); its sites' files belong to it (top folder 0750),
  Nginx is in the account's group so it can still serve them.
- Own PHP-FPM pool per account and PHP version (on demand, max children from the plan's php_workers, own session folder); the
  site's Nginx config talks to that pool; changing the PHP version makes the new pool and removes the unused one.
- As that user: file manager writes / uploads / unzip / new folders, FTP accounts (pure-ftpd), cron shell jobs (and their logs),
  Git deploy, Composer, npm, WordPress (wp-cli), Laravel artisan, background programs (Supervisor), Node.js / Python app services,
  one-click app installs, imports from cPanel / other panels, moves over SSH.
- Imunify sees one user per account with its domains.
- Server tools > Account users: switch it on / off for new sites, list of sites and the user each runs as, "Move all sites"
  (or one) for sites made before: files, pool, Nginx, FTP, cron, workers and app services follow, written to the activity log.
- In 5 languages.

## 0.12.0 (2026-11-08): selling hosting (WHMCS, Blesta) and the rest of the full Webuzo code
Owner: "Webuzo has WHMCS for hosting companies; our panel must do the same, and every other thing in the whole Webuzo code".
The Webuzo code has billing/whmcs (server module + cPanel converter), billing/blesta and plugins (ClamAV, CloudLinux, Imunify360, ImunifyAV).
- Billing API (/api/billing, owner or reseller API token): test, plans, create account (user + plan + website + DNS zone + mail domain,
  nothing half made stays on an error), suspend / unsuspend (websites off, no sign-in, reason kept), terminate (every website, file,
  database, FTP, Redis, mail, DNS, job, backup, token), password, change plan, usage (disk, traffic this month), single sign-on
  (one-time link, 60 seconds). Users page: Delete an account (type the name).
- WHMCS server module (modules/servers/zarspanel): ConfigOptions with the panel's plans, CreateAccount, Suspend, Unsuspend, Terminate,
  ChangePassword, ChangePackage, TestConnection, ServiceSingleSignOn / AdminSingleSignOn, UsageUpdate (tblhosting), client area
  template, admin tab; tested against a running panel. WHMCS addon "ZARS Panel converter": products and services from cPanel /
  Webuzo / DirectAdmin / Plesk servers to the ZARS Panel server in one step.
- Blesta module (components/modules/zarspanel): servers, packages with the plan, add / edit / cancel / suspend / unsuspend /
  change package, service info with usage, client tab "Log in to ZARS Panel".
- Both downloadable from Sell hosting (WHMCS, Blesta), with the steps and the API list.
- Plan feature sets (Webuzo feature sets and ACL, cPanel feature lists): 18 features a plan can switch off (add websites, files,
  SSL, email, DNS, databases, FTP, cron, apps, backups, Redis, Git, WordPress, developer tools, staging, moving sites in, uptime,
  statistics); the server refuses them and the menu hides them. Plans also take disk (MB) and traffic a month (GB).
- ImunifyAV (free) and Imunify360 (licence key): installed with the official deploy script, Imunify's generic panel integration
  (integration.conf + zp imunify users | domains | admins), scan a site or all, clean / restore / ignore what was found.
- Webuzo admin screens not yet here: mail to all clients and news on their home page, password strength (3 levels), fork-bomb
  protection, one TTL for all zones, give a database to another user.
Not taken: CloudLinux (LVE limits are per Linux user; every site here runs as www-data, see the open task on separate Linux users per
account), CSF (ufw + fail2ban do it), IP delegation to resellers (sites listen on every address), Webuzo's own e-mail templates
(the billing system sends the welcome mails).

## 0.11.0 (2026-11-08): every feature of the other control panels
Owner: "cPanel, Webuzo and every other panel, not one feature missing". cPanel and Webuzo were matched in 0.5.0 - 0.8.0; this round
compares Plesk, DirectAdmin, CyberPanel, HestiaCP, aaPanel, CloudPanel, ISPConfig and Virtualmin / Webmin (patterns, own code and words).

| Feature (who has it) | ZARS Panel 0.11.0 |
|---|---|
| Log in as a client (WHM, Plesk, DirectAdmin, HestiaCP) | Users > Sign in as; a bar brings you back; 1 hour; logged |
| Advisor / security score (Plesk, cPanel Security Advisor) | Server tools > Advisor: 13 checks with the page that fixes each |
| History graphs (aaPanel, Plesk, CyberPanel) | CPU, memory, disk, load, network in / out: 24 h / 7 d / 30 d, hover values, table view |
| Services manager (Webmin, aaPanel, Plesk) | Every systemd service: start, stop, restart, at start-up; SSH and the panel protected |
| System log browser (Webmin, Plesk) | journalctl per service, lines, text filter |
| Time zone, time sync, swap (aaPanel, CloudPanel, Webmin) | Time and swap tab; swap file kept in fstab |
| Fail2ban jails (Plesk, DirectAdmin brute force monitor, HestiaCP) | Blocked addresses per jail, unblock, block, ban time / tries / never-block list |
| Country blocking (HestiaCP, aaPanel, CSF) | ipset lists from ipdeny.com, refreshed nightly and at boot; own address kept allowed |
| Panel access by IP, sign-in alerts, session length (aaPanel, Plesk) | Panel access tab (refuses a list without your own address) |
| Remote backups to Google Drive / Dropbox / OneDrive / SFTP / FTP / B2 / WebDAV / pCloud / MEGA (CloudPanel, HestiaCP, Plesk) | rclone remote [zp], every backup copied there; Test button |
| DNS cluster (cPanel, DirectAdmin, HestiaCP, ISPConfig) | Primary: allow-transfer + notify to the listed servers; secondary: slave zones of a primary |
| Cloudflare DNS (CloudPanel, Plesk / cPanel extensions) | API token per user, "Send to Cloudflare" per zone (adds / updates records) |
| Migration from any server (CloudPanel, CyberPanel, Plesk Migrator) | Move from another server: rsync over SSH + mysqldump through SSH, in the background with a live log |
| WordPress toolkit (Plesk, cPanel) | Site > Tools > WordPress: update all / core / plugins / themes, plugins on / off, maintenance, harden, cache, checksums, admin password |
| Laravel toolkit (Plesk) | Artisan commands (safe list), scheduler on / off |
| Background programs / Supervisor (aaPanel, Plesk) | Queue workers and any long-running command per site, logs, restart |
| Staging and push to live (Plesk, CyberPanel) | staging.<domain> with copied databases and adjusted wp-config / .env; push files (and database) after a backup |
| Recycle bin, share links (aaPanel, Plesk) | File manager: deletes wait 30 days; time-limited download links |
| Uptime monitor (Plesk 360, CloudPanel) | Every 5 minutes, alerts on down / up, 30-day percentage |
| Page cache, bad-bot blocking, preview address (Plesk, CloudPanel, CyberPanel LSCache) | nginx FastCGI cache with purge (never for signed-in visitors / carts), bot list, <domain>.<ip>.sslip.io |

OpenLiteSpeed (2.2.0): any PHP website can run on OpenLiteSpeed with LSAPI and LSCache, Nginx stays in front.
Not taken, on purpose: LiteSpeed Enterprise (paid licence), CloudLinux and
Imunify360 (paid products of other companies), a drag-and-drop site builder (ZARS Insights has the website builder), domain
registration (needs a registrar account: part of the billing / WHMCS work).
Installer adds ipset, sshpass, rsync, rclone, supervisor and wp-cli; Software lists rclone, ipset and sshpass for older servers.

## 0.10.0 (2026-11-08): the panel in other languages (cPanel "Change language")
Every user picks the language of their own panel: English, हिन्दी, मराठी, বাংলা, தமிழ் (user menu, and on the sign-in page); kept on the
account (users.language, POST /api/me/language). The English text is the key (gettext pattern) in web/src/lang/<code>.json, loaded only when
chosen; one translator shows every known text, placeholder, title and screen-reader label in that language. Product names (Redis, Docker,
PHP) and examples stay as they are. tools/extract-strings.mjs --missing <code> lists what a language still misses; tests/lang-qa.cjs checks
the switch; ui-qa.cjs with LANG_CODE=hi checks every page in a language.

## 0.9.0 (2026-11-08): a new look that shows every feature
Owner: "all cPanel features, and a completely new UI". The features were there (see 0.5.0 cPanel parity) but hidden in tabs.
New UI: navy menu grouped like a hosting panel, glass top bar with feature search, light / dark switch and a user menu; the home page shows
the server at a glance and every feature as a tile in cPanel-style groups (websites and domains, files and backups, databases, email,
security, statistics and logs, software, advanced, server, clients, account) that opens the exact page and tab (#tools/mailx); tables turn
into cards on phones; nothing scrolls sideways.

## 0.8.0 (2026-11-05): the five Webuzo parts left after 0.7.0
- Webuzo backups: "Move from cPanel / Webuzo" also takes a Webuzo full backup (read from Webuzo's own backup and restore code): domains as sites with their files, own SSL certificates, every database of the dump, mailboxes with their old password hashes, quota and mail; the old cron lines are shown to add by hand.
- Script catalogue (as Softaculous): 13 more apps, always the newest version from the project's own download (fixed "latest" links or the newest GitHub release): Joomla, Drupal, Grav, OpenCart, Nextcloud, Piwigo, Lychee, Matomo, Mautic, Akaunting, osTicket, YOURLS, FreshRSS; site + own database, the database details for the app's set-up page; App store grouped with a search.
- Plugins: a zip with plugin.json + plugin.php adds routes, a page and 5-minute jobs; installed switched off, switched on by the owner typing its key; a plugin that fails while starting is switched off with its error.
- Sending limits: messages an hour per account and per reseller (all clients together), through the panel's own Postfix policy service; over the limit mail waits, nothing is lost.
- Tomcat manager password (user admin, stored as a salted SHA-512 digest).
Fixed: uploads from a real browser (files[] arrives as parallel arrays) were skipped, also in the file manager.
Left on purpose: Softaculous' paid catalogue of 400+ scripts (only free projects with an official download are listed).

## 0.7.0 (2026-11-05): the last Webuzo screens (all 79 user and 167 admin screens of the given code checked one by one)
Server tools, 8 new tabs (owner only, every change in the activity log):
- Mail settings: relay through another server (smarthost with login), local or remote mail per domain (Google Workspace / Zoho), refuse senders, domains and countries, scripts may not send mail directly (only the mail server talks SMTP), where the server's own mail goes, the mail services use a site's certificate.
- Mail log: sent summary per sender, track one address (sent / waiting / bounced with the other server's answer).
- FTP sessions: who is connected, close a session.
- Process rules: programs always stopped (every 5 minutes), CPU and memory per Linux user every hour (7 days).
- PHP-FPM pool, MySQL / MariaDB settings (old ones come back when the server does not start), database root password, Apache settings.
- Users and access: sudo administrators, shell access per Linux user, compilers only for root, package database repair.
- IPs, disks and sites: shared address for new sites, server IP change in every DNS zone (A and SPF), disks and the backup folder, site owner change (with its databases, FTP users, zone, mail), move a site to another disk, extra nginx lines per site (checked by nginx -t).
- Export and clean-up: settings and hosting plans to another server (secrets stay), unused DNS zones, monthly traffic from zero.
Fixed: a generated password could miss a digit (about 1 in 11), so a cPanel import failed now and then.
The five parts not taken in 0.7.0 came in 0.8.0.

## 0.6.1 (2026-11-05): server jobs like Webuzo

- Cron: service check every 5 minutes, daily update check (installs by itself when Admin > Automatic updates is on, the default), nightly clean-up.
- Updates come from ZARS Insights: https://insights.zarsx.in/updates/zarspanel/latest.json (signed, checksum checked).

## 0.6.0 (2026-11-05): server software catalogue (Webuzo's Apps)
Server tools > Software: 40 programs a hosting server runs - web servers (Nginx, Apache, Varnish, HAProxy), languages (PHP 7.4 / 8.1 - 8.4, Node.js LTS, Python, Java, Ruby, Go, Composer), databases (MariaDB, MySQL, PostgreSQL, Redis, Memcached, SQLite), mail (Postfix, Dovecot, SpamAssassin, ClamAV, OpenDKIM), DNS and FTP (BIND, Pure-FTPd, ProFTPD), security (Fail2ban, UFW, ModSecurity OWASP, Certbot) and tools (Git, ImageMagick, FFmpeg, wkhtmltopdf, Docker, Supervisor, rsync) - each with its installed and newest version and Install / Update / Remove. Nothing is compiled on the server (Webuzo compiled its own builds): packages come from Ubuntu / Debian, and "Newest version" adds the program's own official repository once (PHP: Ondrej Sury, Node.js: NodeSource, MariaDB: mariadb.org, PostgreSQL: PGDG, Redis: redis.io). The programs the panel needs (Nginx, MariaDB, UFW, Fail2ban) cannot be removed. Perl / PEAR modules moved to their own tab.
Server jobs as Webuzo's (set by the installer and on every cron render, /etc/cron.d/zarspanel): SSL renewal (daily), alerts (5 min), bandwidth (hourly), service check that starts a stopped core service (5 min), update check every night that installs a new release by itself (Admin > Updates: "Install new versions by themselves", on by default; a version pin stops it), clean-up of old temporary files (daily). Updates come from ZARS Insights: https://insights.zarsx.in/updates/zarspanel/latest.json (Control center > System > Product updates).

