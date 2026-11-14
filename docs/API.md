# API notes

Authentication: `POST /api/login {name, password, code?}` sets a session cookie (and returns a `csrf` value to send as `X-CSRF-Token` on every POST), or send `Authorization: Bearer zp_...` (no CSRF needed).

| Area | Endpoints |
|---|---|
| Overview | GET /api/overview |
| Websites | GET/POST /api/sites, GET/POST /api/sites/{id}, POST .../toggle, .../delete, .../ssl, GET .../logs?type=access|error |
| Files | GET .../files?path=, GET/POST .../file, POST .../files/{mkdir,delete,rename,chmod,zip,unzip}, POST .../upload?path= |
| Databases | GET/POST /api/databases, POST .../{id}/password, .../delete, .../import |
| Scheduled jobs | GET/POST /api/cron, POST .../{id}/toggle, .../delete |
| Backups | GET /api/backups, POST /api/backups/site/{id}, /api/backups/database/{id}, POST .../{id}/restore, .../delete, GET .../{id}/download |
| Apps | GET /api/apps, POST /api/apps/{key}/install (Laravel: with "source" zip address), POST /api/apps/{key}/source (owner) |
| Firewall (owner) | GET /api/firewall, POST allow, deny, block-ip, rules/{n}/delete, enable, ssh |
| Users | GET/POST /api/users, POST /api/users/{id} (limits, block, password, brand) |
| Account | POST /api/account/password, two-step/{start,confirm,off}, GET/POST /api/account/tokens |
| Owner | GET/POST /api/alerts, GET /api/updates, POST /api/updates/apply, POST /api/settings/{brand,backups}, GET /api/audit |
