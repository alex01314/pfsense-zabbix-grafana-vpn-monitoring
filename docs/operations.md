# Operação e troubleshooting

## Agendamento usado no projeto

Pelo pacote Cron do pfSense, uma tarefa root com minuto, hora, dia do mês, mês e dia da semana iguais a `*`. Comando documentado, que depende de scripts publicados em `collector/`, instalados e configurados localmente:

```sh
/usr/bin/lockf -k -t 0 /var/run/zabbix-vpn.lock /bin/sh -c '{ /usr/local/bin/php /usr/local/share/zabbix-vpn/collect.php >/dev/null; /usr/local/bin/php /usr/local/share/zabbix-vpn/send.php; } >/var/log/zabbix-vpn-last.log 2>&1'
```

`lockf` impede duas instâncias simultâneas; `-t 0` não espera quando ocupado. `-k` mantém o arquivo, mas o bloqueio é liberado ao encerrar. O `;` permite enviar um snapshot novo de falha produzido pelo coletor. O sender deve rejeitar arquivo antigo. O log é sobrescrito a cada execução, não mantém histórico.

O coletor precisa de privilégio para consultar o serviço IPsec. Isso não exige liberar shell remoto para o usuário do Zabbix. Restringir gravação dos scripts a root e leitura do snapshot a quem realmente precisa. Não editar crontab gerada pelo pfSense manualmente.

## Verificações

1. Conferir sintaxe dos scripts locais com `php -l`.
2. Rodar coleta e validar `collection_ok`, timestamp, inventário e estados.
3. Conferir resposta do envio: processamento aceito e nenhum item rejeitado.
4. Conferir histórico em Latest data, com duas ou mais amostras separadas pelo intervalo configurado.
5. Validar comportamento de falha e recuperação em laboratório.

Uma linha contínua num gráfico e o valor UP isolado não provam execução periódica.

## Quando uma VPN aparece DOWN

Comparar o estado no dashboard ao Status IPsec do pfSense. Consultar os logs IPsec e correlacionar conexão, horário, IP de origem e destino. Retransmissões indicam tentativa sem progresso esperado; não provam sozinhas bloqueio, senha incorreta ou defeito da operadora.

Em captura autorizada e limitada, observar UDP 500/4500 em ambas as direções. Ping ao IP público pode usar outra origem e só comprova ICMP naquele caminho. Não publicar a captura: contém metadados da infraestrutura.

Se necessário, combinar teste nas duas pontas e comparar recebimento e resposta antes de alterar configuração. Reiniciar todo o serviço IPsec pode afetar túneis saudáveis.

## Evolução planejada

- Timeout de processo e métricas de duração da coleta.
- Estado UNKNOWN por expiração dos dados.
- Inventário externo com validação.
- Testes de parser com rekey, múltiplos CHILDs e saída vazia.
- Tráfego de VPNs baseadas em políticas com tratamento de rekey.
- Exportações sanitizadas e importáveis de template e dashboard.
