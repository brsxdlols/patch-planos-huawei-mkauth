#!/bin/bash
set -eu

REPO_API="${PATCH_HUAWEI_REPO_API:-https://api.github.com/repos/brsxdlols/patch-planos-huawei-mkauth/contents}"
INSTALL_DIR="/root/planos"
CRON_TAG="# patch-planos-huawei-mkauth"
CRON_LINE="*/1 * * * * sh ${INSTALL_DIR}/att-planos-huawei.sh >> /var/log/patch-planos-huawei.log 2>&1 ${CRON_TAG}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Erro: execute este instalador como root." >&2
  exit 1
fi

for command in curl mysql crontab; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Erro: comando obrigatório não encontrado: $command" >&2
    exit 1
  fi
done

mkdir -p "$INSTALL_DIR"

cat > "${INSTALL_DIR}/mysql.cnf" <<'EOF'
[client]
user=root
password=vertrigo
database=mkradius
host=localhost
EOF
chmod 600 "${INSTALL_DIR}/mysql.cnf"

for script in att-planos-huawei.sh planos.sh att-planos.sh; do
  curl -fsSL \
    -H 'Accept: application/vnd.github.raw' \
    "${REPO_API}/planos/${script}?ref=main" \
    -o "${INSTALL_DIR}/${script}"
  chmod 700 "${INSTALL_DIR}/${script}"
done

ADDON_DIR="/opt/mk-auth/admin/addons/huawei_online"
mkdir -p "$ADDON_DIR"
for addon_file in index.php api.php manifest.json; do
  curl -fsSL \
    -H 'Accept: application/vnd.github.raw' \
    "${REPO_API}/addon/huawei_online/${addon_file}?ref=main" \
    -o "${ADDON_DIR}/${addon_file}"
done
curl -fsSL \
  -H 'Accept: application/vnd.github.raw' \
  "${REPO_API}/addon/addon_huawei_online.js?ref=main" \
  -o /opt/mk-auth/admin/addons/addon_huawei_online.js
chown -R www-data:www-data "$ADDON_DIR" /opt/mk-auth/admin/addons/addon_huawei_online.js
chmod 640 "$ADDON_DIR"/* /opt/mk-auth/admin/addons/addon_huawei_online.js

mysql --defaults-extra-file="${INSTALL_DIR}/mysql.cnf" <<'SQL'
DROP TRIGGER IF EXISTS sis_cliente_lowercase_mac;
DELIMITER //
CREATE TRIGGER sis_cliente_lowercase_mac
BEFORE UPDATE ON sis_cliente
FOR EACH ROW
BEGIN
  SET NEW.mac = LOWER(NEW.mac);
END//
DELIMITER ;
SQL

current_cron="$(crontab -l 2>/dev/null || true)"
clean_cron="$(printf '%s\n' "$current_cron" | grep -v 'patch-planos-huawei-mkauth' || true)"
{
  printf '%s\n' "$clean_cron"
  printf '%s\n' "$CRON_LINE"
} | awk 'NF && !seen[$0]++' | crontab -

sh "${INSTALL_DIR}/att-planos-huawei.sh"

echo "Patch Planos Huawei instalado com sucesso."
echo "Log: /var/log/patch-planos-huawei.log"
