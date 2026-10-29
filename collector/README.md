# Coletor e sender sanitizados

PHP 8.2 CLI. Adaptados dos scripts fornecidos pelo autor, preservando parsing, correlação e protocolo Sender. Inventário e destino foram externalizados; o ambiente de origem não foi alterado.

## Inventário fictício

| VPN | Identificador | Caminho | Correspondência |
| --- | --- | --- | --- |
| VPN 1 | `ipsec101` | Link A | reqid `6101` |
| VPN 1 | `ipsec102` | Link B | reqid `6102` |
| VPN 2 | `con-lab-2` | vazio | CHILDs `child-lab-2a`, `child-lab-2b` |

Os identificadores são fictícios; ajustar aos do laboratório. `examples/status.json` usa o mesmo inventário e timestamp fixo; o sender deve rejeitá-lo como antigo.

## Configuração

Instalar esta pasta em `/usr/local/share/zabbix-vpn` no laboratório. Criar arquivo privado:

```sh
cd /usr/local/share/zabbix-vpn
cp config.example.php config.php
chmod 0640 config.php
```

Editar `config.php`: inventário VTI e policy, grupo e caminho do snapshot, servidor, porta, nome técnico do host Zabbix e chave mestre. O destino `zabbix.example.invalid` é fictício. `config.php` está ignorado pelo Git; não publicar configuração real.

Depois de configurar, validar e executar no laboratório:

```sh
/usr/local/bin/php -l collect.php
/usr/local/bin/php -l send.php
/usr/local/bin/php -l config.php
/usr/local/bin/php collect.php
/usr/local/bin/php send.php
```

O diretório de saída deve existir e permitir temporários pelo coletor. O snapshot recebe grupo configurado e permissão 0640. Ver [operação](../docs/operations.md) para Cron.

## Alterações e limites

Inventário real substituído por VPN 1 e VPN 2. Consulta não inclui saída bruta em mensagens de erro; sender não despeja resposta integral do servidor. Lógica de estados e enquadramento Sender preservados.

O sender usa TCP sem TLS: usar rede controlada e origem restrita. O coletor ainda utiliza `exec` sem timeout e duas passagens pelo texto. Testes de parser, tratamento aprimorado de saída vazia e estado por dados vencidos ficam para próxima etapa.

O ambiente de preparação não tinha PHP instalado. Sintaxe e execução ponta a ponta desta versão sanitizada precisam ser validadas no laboratório. O validador Python verifica fixtures e links, sem executar PHP.
