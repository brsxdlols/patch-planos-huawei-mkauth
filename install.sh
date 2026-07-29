#!/bin/bash
set -eu

REPO_RAW="${PATCH_HUAWEI_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/patch-planos-huawei-mkauth/main}"
INSTALL_DIR="/root/planos"
CRON_TAG="# patch-planos-huawei-mkauth"
CRON_LINE="*/1 * * * * sh ${INSTALL_DIR}/att-planos-huawei.sh >> /var/log/patch-planos-huawei.log 2>&1 ${CRON_TAG}"
CACHE_BUSTER="$(date +%s)"

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
  curl -fsSL "${REPO_RAW}/planos/${script}?v=${CACHE_BUSTER}" -o "${INSTALL_DIR}/${script}"
  chmod 700 "${INSTALL_DIR}/${script}"
done

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
