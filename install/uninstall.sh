#!/usr/bin/env bash
# Removes the panel service and files. Websites, databases and nginx / MariaDB stay as they are.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run as root." >&2; exit 1; }
read -r -p "Remove ZARS Panel (websites and databases are kept)? Type yes: " a
[ "$a" = "yes" ] || exit 0
systemctl disable --now zarspanel 2>/dev/null || true
rm -f /etc/systemd/system/zarspanel.service /etc/nginx/zarspanel-panel.conf /etc/cron.d/zarspanel
sed -i '/zarspanel-panel.conf/d' /etc/nginx/conf.d/zarspanel.conf 2>/dev/null || true
systemctl daemon-reload; nginx -t && systemctl reload nginx
echo "The panel is removed. Data kept in /opt/zarspanel/data and /www/wwwroot; delete /opt/zarspanel yourself if you want."
