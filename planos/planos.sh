#!/bin/bash
set -eu

mysql --defaults-extra-file=/root/planos/mysql.cnf \
  --batch --skip-column-names \
  -e "SELECT nome, velup, veldown FROM sis_plano WHERE nome <> '' AND velup IS NOT NULL AND veldown IS NOT NULL;"
