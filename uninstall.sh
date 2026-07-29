#!/bin/bash
set -eu

if [ "$(id -u)" -ne 0 ]; then
  echo "Erro: execute este desinstalador como root." >&2
  exit 1
fi

current_cron="$(crontab -l 2>/dev/null || true)"
printf '%s\n' "$current_cron" |
  grep -v 'patch-planos-huawei-mkauth' |
  crontab - || true

mysql --defaults-extra-file=/root/planos/mysql.cnf \
  -e 'DROP TRIGGER IF EXISTS sis_cliente_lowercase_mac;'

rm -rf /root/planos

echo "Patch removido. Os atributos já sincronizados foram preservados no banco."
