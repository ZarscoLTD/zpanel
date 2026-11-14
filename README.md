# ZARS Panel Community

The free Community edition of ZARS Panel with only the basic needs of one server: websites with PHP and free SSL, the file manager,
databases, scheduled jobs, backups, the firewall and SSH, server status, two-step sign-in. Plain PHP back end and a React web app.
It is not updated on a fixed schedule; you are welcome to send improvements (pull requests).

Email, DNS, FTP, the app store, resellers and hosting plans, selling hosting with WHMCS / Blesta, moving from cPanel / Webuzo,
the virus scanner, Docker, the terminal, signed automatic updates and help from our team are in the full ZARS Panel
(a paid licence per server): https://panel.zarsx.in - prices: https://panel.zarsx.in/pricing - compare the editions: https://panel.zarsx.in/#compare

Community edition 2.2.0 by Zars Company (ZARSCO). Free to use, no licence key and no fee (see LICENSE for the short terms).

## Install (fresh Ubuntu 22.04 / 24.04 or Debian 12 server, as root)

    bash install/install.sh            # optional: --port 9443  --no-firewall

It prints the address, the user `admin` and a one-time password. Change the password and switch on two-step sign-in first.

## Develop

- `src/` back end, `public/index.php` front controller, `web/` React source (`cd web && npm ci && npm run build` writes `public/app`).
- Check: `php tools/community-smoke.php .` (dry run, touches nothing on the machine).
- Every system command goes through `src/Exec.php` as an argument list; keep it that way.

## Updates

This edition does not take the signed ZARSCO update packages; update it from this repository (git pull, then run the installer
again with the same options).
