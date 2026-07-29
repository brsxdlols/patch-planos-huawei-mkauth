#!/bin/bash
set -eu

plano="${1:-}"
up="${2:-}"
down="${3:-}"

case "$up:$down" in
  *[!0-9:]*|:*|*:)
    echo "Plano ignorado por possuir velocidade inválida: $plano ($up/$down)" >&2
    exit 1
    ;;
esac

if [ -z "$plano" ]; then
  echo "Plano ignorado por possuir nome vazio." >&2
  exit 1
fi

if [ "$up" -gt 4294967 ] || [ "$down" -gt 4294967 ]; then
  echo "Plano ignorado: velocidade excede o limite do atributo Huawei integer: $plano ($up/$down)" >&2
  exit 1
fi

plano_b64="$(printf '%s' "$plano" | base64 | tr -d '\r\n')"
up_value="${up}000"
down_value="${down}000"

mysql --defaults-extra-file=/root/planos/mysql.cnf <<SQL
DELETE FROM radgroupreply
 WHERE HEX(groupname) = HEX(FROM_BASE64('${plano_b64}'))
   AND HEX(attribute) IN (
     HEX('Huawei-Input-Average-Rate'),
     HEX('Huawei-Output-Average-Rate')
   );

INSERT INTO radgroupreply (groupname, attribute, op, value)
VALUES
  (CONVERT(FROM_BASE64('${plano_b64}') USING utf8mb4), 'Huawei-Input-Average-Rate', '=', '${up_value}'),
  (CONVERT(FROM_BASE64('${plano_b64}') USING utf8mb4), 'Huawei-Output-Average-Rate', '=', '${down_value}');
SQL
