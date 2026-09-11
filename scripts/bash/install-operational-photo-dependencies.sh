#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Execute como root: sudo $0" >&2
  exit 1
fi

apt-get update
apt-get install -y --no-install-recommends \
  libvips-tools \
  libheif-plugin-aomdec \
  libheif-plugin-aomenc \
  libheif-plugin-libde265

photo_tmp_dir=/var/www/sistema-erp/backend/storage/app/private/operational-photo-tmp

mkdir -p "${photo_tmp_dir}"
chown -R www-data:www-data "${photo_tmp_dir}"
chmod 0700 "${photo_tmp_dir}"

if command -v runuser >/dev/null 2>&1; then
  runuser -u www-data -- php /var/www/sistema-erp/backend/artisan photos:preflight
else
  sudo -u www-data php /var/www/sistema-erp/backend/artisan photos:preflight
fi
