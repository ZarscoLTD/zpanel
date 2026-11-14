#!/usr/bin/env bash
# ZARS Panel installer for a fresh Ubuntu 22.04 / 24.04 or Debian 12 server. Run as root:
#   bash install.sh [--port 8443] [--no-firewall] [--no-extras]   (extras: DNS, FTP, mail, Redis, Docker, antivirus)
# It installs nginx, MariaDB, PHP, certbot, ufw and fail2ban, puts the panel under /opt/zarspanel and prints the address and login at the end.
set -euo pipefail

PORT=8443
FIREWALL=1
EXTRAS=1
while [ $# -gt 0 ]; do
  case "$1" in
    --port) PORT="${2:-}"; shift 2 ;;
    --no-firewall) FIREWALL=0; shift ;;
    --no-extras) EXTRAS=0; shift ;;
    -h|--help) sed -n 2,5p "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done
case "$PORT" in ''|*[!0-9]*) echo "The port must be a number." >&2; exit 2 ;; esac
[ "$PORT" -ge 1024 ] && [ "$PORT" -le 65535 ] || { echo "Use a port between 1024 and 65535." >&2; exit 2; }
[ "$(id -u)" -eq 0 ] || { echo "Run this as root (sudo bash install.sh)." >&2; exit 1; }
[ -d /opt/zarspanel/current ] && { echo "ZARS Panel is already installed. Update it from the panel (Settings > Updates)." >&2; exit 1; }

. /etc/os-release
# Ubuntu-based systems (Linux Mint 21 / 22, Pop!_OS, Zorin, elementary ...) use the packages of their Ubuntu base (UBUNTU_CODENAME)
BASE="${ID}:${VERSION_ID}"
case "${UBUNTU_CODENAME:-}" in jammy) [ "$ID" = ubuntu ] || BASE="ubuntu:22.04" ;; noble) [ "$ID" = ubuntu ] || BASE="ubuntu:24.04" ;; esac
case "$BASE" in
  ubuntu:22.04|ubuntu:24.04|debian:12) ;;
  *) echo "This installer supports Ubuntu 22.04 / 24.04 (and systems built on them, like Linux Mint 21 / 22) and Debian 12 (found ${PRETTY_NAME:-unknown})." >&2; exit 1 ;;
esac
[ "$BASE" != "${ID}:${VERSION_ID}" ] && echo "==> ${PRETTY_NAME}: using the packages of its base ($BASE)." 
MEM_MB=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)
[ "$MEM_MB" -ge 900 ] || echo "Warning: only ${MEM_MB} MB of memory. 1 GB or more is recommended."

HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(cat "$HERE/VERSION")"
say() { printf '\n==> %s\n' "$*"; }

say "Installing packages (this takes a few minutes)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx mariadb-server postgresql certbot ufw fail2ban unzip curl openssl cron ca-certificates tar gzip \
  php-cli php-fpm php-mysql php-sqlite3 php-curl php-mbstring php-xml php-zip php-gd php-intl php-bcmath \
  ipset sshpass rsync rclone supervisor >/dev/null
# WordPress manager: wp-cli from its official release
[ -x /usr/local/bin/wp ] || { curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod 755 /usr/local/bin/wp; } || true
systemctl enable --now supervisor >/dev/null 2>&1 || true
mkdir -p /var/cache/nginx/zp && chown www-data:www-data /var/cache/nginx/zp
# the PHP that php-fpm brought (not the "php" command: a desktop with several PHP versions may point it at another one)
PHPV="$(dpkg-query -W -f='${Depends}' php-fpm 2>/dev/null | grep -oE 'php[0-9]+\.[0-9]+-fpm' | head -1 | sed -E 's/php([0-9.]+)-fpm/\1/')"
[ -n "$PHPV" ] || PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
"php$PHPV" -r 'exit(function_exists("sodium_crypto_sign_keypair") ? 0 : 1);' || { echo "PHP needs the sodium extension." >&2; exit 1; }
systemctl enable --now nginx mariadb postgresql cron "php${PHPV}-fpm" >/dev/null 2>&1 || true

say "Placing the panel in /opt/zarspanel"
install -d -m 0750 /opt/zarspanel /opt/zarspanel/releases /opt/zarspanel/data
rm -rf "/opt/zarspanel/releases/$VERSION"
mkdir -p "/opt/zarspanel/releases/$VERSION"
(cd "$HERE" && tar --exclude='./.git' --exclude='./data' --exclude='./web/node_modules' --exclude='./.update-secret.key.b64' -cf - .) | tar -xf - -C "/opt/zarspanel/releases/$VERSION"
ln -sfn "/opt/zarspanel/releases/$VERSION" /opt/zarspanel/current
chmod +x /opt/zarspanel/current/bin/zp
install -d -m 0755 /www/wwwroot /www/wwwlogs /etc/nginx/zarspanel

say "Web server and panel service"
cat > /etc/nginx/conf.d/zarspanel.conf <<NGINX
map \$http_upgrade \$connection_upgrade { default upgrade; '' close; }
include /etc/nginx/zarspanel/*.conf;
NGINX
rm -f /etc/nginx/sites-enabled/default
install -d -m 0700 /opt/zarspanel/ssl
openssl req -x509 -newkey rsa:3072 -nodes -days 3650 -subj "/CN=$(hostname -f 2>/dev/null || hostname)" -keyout /opt/zarspanel/ssl/panel.key -out /opt/zarspanel/ssl/panel.crt >/dev/null 2>&1
mkdir -p /etc/nginx/zarspanel-tools
cat > /etc/nginx/zarspanel-panel.conf <<NGINX
server {
    listen ${PORT} ssl http2;
    listen [::]:${PORT} ssl http2;
    server_name _;
    ssl_certificate /opt/zarspanel/ssl/panel.crt;
    ssl_certificate_key /opt/zarspanel/ssl/panel.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 1024m;
    add_header Strict-Transport-Security "max-age=31536000" always;
    include /etc/nginx/zarspanel-tools/*.conf; # phpMyAdmin, webmail, calendars once installed from the panel
    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 900s;
    }
}
NGINX
cat >> /etc/nginx/conf.d/zarspanel.conf <<NGINX
include /etc/nginx/zarspanel-panel.conf;
NGINX
cat > /etc/systemd/system/zarspanel.service <<UNIT
[Unit]
Description=ZARS Panel
After=network.target mariadb.service

[Service]
Environment=PHP_CLI_SERVER_WORKERS=6
Environment=ZARSPANEL_HOME=/opt/zarspanel/data
Environment=ZARSPANEL_PORT=${PORT}
WorkingDirectory=/opt/zarspanel/current
ExecStart=/usr/bin/php${PHPV} -d upload_max_filesize=1024M -d post_max_size=1024M -d memory_limit=512M -d display_errors=0 -d log_errors=1 -S 127.0.0.1:8081 -t public public/index.php
Restart=always
RestartSec=3
NoNewPrivileges=false

[Install]
WantedBy=multi-user.target
UNIT
nginx -t >/dev/null
systemctl daemon-reload
systemctl enable --now zarspanel >/dev/null
systemctl reload nginx

say "First login"
ADMIN_PASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)A1"
ZARSPANEL_HOME=/opt/zarspanel/data /opt/zarspanel/current/bin/zp create-owner admin "$ADMIN_PASS" >/dev/null
ZARSPANEL_HOME=/opt/zarspanel/data /opt/zarspanel/current/bin/zp cron-render >/dev/null
chmod 750 /opt/zarspanel/data

if [ "$EXTRAS" -eq 1 ]; then
  say "Extras: DNS, FTP, mail, Redis, Docker, antivirus"
  echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections
  echo "postfix postfix/mailname string $(hostname -f 2>/dev/null || hostname)" | debconf-set-selections
  apt-get install -y -qq bind9 bind9utils pure-ftpd postfix dovecot-core dovecot-imapd dovecot-lmtpd opendkim opendkim-tools redis-server clamav docker.io \
    dovecot-sieve postgrey rspamd goaccess git libnginx-mod-http-modsecurity modsecurity-crs whois traceroute dnsutils logrotate >/dev/null || true
  systemctl disable --now redis-server >/dev/null 2>&1 || true   # panel Redis instances replace the default one
  # DNS: the panel writes /etc/bind/named.conf.zarspanel and /etc/bind/zones/db.<domain>
  mkdir -p /etc/bind/zones; touch /etc/bind/named.conf.zarspanel
  grep -q named.conf.zarspanel /etc/bind/named.conf.local 2>/dev/null || echo 'include "/etc/bind/named.conf.zarspanel";' >> /etc/bind/named.conf.local
  touch /etc/bind/named.conf.zp-cluster
  grep -q named.conf.zp-cluster /etc/bind/named.conf.local 2>/dev/null || echo 'include "/etc/bind/named.conf.zp-cluster";' >> /etc/bind/named.conf.local
  systemctl enable --now bind9 >/dev/null 2>&1 || systemctl enable --now named >/dev/null 2>&1 || true
  # FTP: virtual users locked into their folder
  echo yes > /etc/pure-ftpd/conf/ChrootEveryone; echo yes > /etc/pure-ftpd/conf/CreateHomeDir
  echo /etc/pure-ftpd/pureftpd.pdb > /etc/pure-ftpd/conf/PureDB
  ln -sf /etc/pure-ftpd/conf/PureDB /etc/pure-ftpd/auth/50pure
  systemctl enable --now pure-ftpd >/dev/null 2>&1 || true
  # Redis: one unit per instance (/etc/redis/zp-<name>.conf)
  cat > /etc/systemd/system/zp-redis@.service <<'UNIT'
[Unit]
Description=ZARS Panel Redis %i
After=network.target
[Service]
User=redis
ExecStartPre=+/bin/chown redis /etc/redis/zp-%i.conf
ExecStart=/usr/bin/redis-server /etc/redis/zp-%i.conf
Restart=on-failure
[Install]
WantedBy=multi-user.target
UNIT
  mkdir -p /etc/redis /opt/zarspanel/data/redis; chown redis /opt/zarspanel/data/redis 2>/dev/null || true
  # mail: virtual domains from the panel maps, Dovecot password file
  id vmail >/dev/null 2>&1 || { groupadd -g 5000 vmail; useradd -g vmail -u 5000 -d /var/vmail -m -s /usr/sbin/nologin vmail; }
  mkdir -p /etc/zarspanel-mail; touch /etc/zarspanel-mail/{virtual_domains,vmailbox,virtual_aliases,dovecot-users}
  for f in virtual_domains vmailbox virtual_aliases; do postmap /etc/zarspanel-mail/$f; done
  postconf -e "virtual_mailbox_domains = hash:/etc/zarspanel-mail/virtual_domains" "virtual_mailbox_maps = hash:/etc/zarspanel-mail/vmailbox" \
    "virtual_alias_maps = hash:/etc/zarspanel-mail/virtual_aliases" "virtual_mailbox_base = /var/vmail" "virtual_uid_maps = static:5000" "virtual_gid_maps = static:5000" \
    "smtpd_sasl_type = dovecot" "smtpd_sasl_path = private/auth" "smtpd_sasl_auth_enable = yes" "smtpd_recipient_restrictions = permit_sasl_authenticated, reject_unauth_destination"
  cat > /etc/dovecot/conf.d/99-zarspanel.conf <<'DOVE'
mail_location = maildir:/var/vmail/%d/%n
mail_uid = 5000
mail_gid = 5000
passdb { driver = passwd-file
  args = scheme=SHA512-CRYPT username_format=%u /etc/zarspanel-mail/dovecot-users }
userdb { driver = static
  args = uid=5000 gid=5000 home=/var/vmail/%d/%n }
service auth { unix_listener /var/spool/postfix/private/auth { mode = 0660
  user = postfix
  group = postfix } }
# delivery through LMTP so filters and out-of-office replies (Sieve) run
service lmtp { unix_listener /var/spool/postfix/private/dovecot-lmtp { mode = 0600
  user = postfix
  group = postfix } }
protocol lmtp { mail_plugins = $mail_plugins sieve }
plugin { sieve = file:/etc/dovecot/sieve/%u.sieve }
DOVE
  mkdir -p /etc/dovecot/sieve; chown -R vmail:vmail /etc/dovecot/sieve
  postconf -e "virtual_transport = lmtp:unix:private/dovecot-lmtp"
  # web application firewall for sites that switch it on (OWASP core rules)
  if [ -d /usr/share/modsecurity-crs ]; then
    mkdir -p /etc/nginx/modsec
    [ -f /etc/nginx/modsec/main.conf ] || printf 'Include /etc/modsecurity/modsecurity.conf-recommended\nSecRuleEngine On\nInclude /usr/share/modsecurity-crs/crs-setup.conf\nInclude /usr/share/modsecurity-crs/rules/*.conf\n' > /etc/nginx/modsec/main.conf
  fi
  systemctl enable --now postfix dovecot opendkim docker >/dev/null 2>&1 || true
  systemctl restart postfix dovecot >/dev/null 2>&1 || true
  for p in 53 21 25 587 993; do ufw allow "$p" >/dev/null 2>&1 || true; done
  freshclam --quiet >/dev/null 2>&1 || true
fi

if [ "$FIREWALL" -eq 1 ]; then
  say "Firewall"
  SSHP="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')"; SSHP="${SSHP:-22}"
  for p in "$SSHP" 80 443 "$PORT"; do ufw allow "$p/tcp" >/dev/null; done
  ufw --force enable >/dev/null
fi
systemctl enable --now fail2ban >/dev/null 2>&1 || true

IP="$(curl -fsS -m 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"
cat <<DONE

ZARS Panel ${VERSION} is installed.

  Address : https://${IP}:${PORT}   (the browser warns about the certificate until you use a domain; that is expected)
  User    : admin
  Password: ${ADMIN_PASS}

Save the password now. Change it after signing in (My account) and switch on two-step sign-in.
DONE
