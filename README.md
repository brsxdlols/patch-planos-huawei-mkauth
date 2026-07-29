# Patch Planos Huawei para MK-AUTH

Sincroniza os planos do MK-AUTH com os atributos de velocidade Huawei no
FreeRADIUS:

- `Huawei-Input-Average-Rate`
- `Huawei-Output-Average-Rate`

O instalador copia os scripts para `/root/planos`, cria a trigger que normaliza
o MAC dos clientes em letras minúsculas e configura a execução automática a
cada minuto. Também instala o addon **Huawei Online** no painel do MK-AUTH.

O addon permite selecionar o NAS Huawei e apresenta clientes conectados,
tráfego acumulado e taxas calculadas entre dois pacotes RADIUS
`Interim-Update`.

## Instalação

Execute como `root`:

```bash
curl -fsSL -H 'Accept: application/vnd.github.raw' \
  https://api.github.com/repos/brsxdlols/patch-planos-huawei-mkauth/contents/install.sh |
  bash
```

## Verificação

```bash
crontab -l | grep patch-planos-huawei
sh /root/planos/att-planos-huawei.sh
tail -n 50 /var/log/patch-planos-huawei.log
```

## Remoção

```bash
curl -fsSL -H 'Accept: application/vnd.github.raw' \
  https://api.github.com/repos/brsxdlols/patch-planos-huawei-mkauth/contents/uninstall.sh |
  bash
```

Por segurança, a remoção não apaga os atributos já sincronizados no banco.

## Observações

- Compatível com a instalação padrão do MK-AUTH que usa o banco `mkradius` e
  a senha MySQL `vertrigo`.
- O MK-AUTH armazena `velup` e `veldown` em Kbps; o patch multiplica esses
  valores por 1.000 para enviar os atributos Huawei em bps.
- Somente `att-planos-huawei.sh` é agendado. Ele chama os outros scripts com
  os parâmetros necessários.
- O instalador pode ser executado novamente para atualizar ou reparar o patch.
