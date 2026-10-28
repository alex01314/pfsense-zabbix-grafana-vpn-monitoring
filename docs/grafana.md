# Dashboard Grafana

Receita manual para datasource Zabbix. Não é export JSON importável; nomes dos controles variam por versão do plugin.

## Estados

Painel Stat, consulta Metrics, host `vpn-lab`, item `/.*Estado da VPN.*/`. Começar sem filtro de tag e validar retorno no Query inspector antes de adicionar filtros.

Transformação Rename fields by regex:

```text
Match: ^(.*): Estado da VPN$
Replace: $1
```

Mapear `1` para UP/verde, `0` para DOWN/vermelho e `2` para PARCIAL/amarelo. Exibir ausência de dados com texto e cor distintos. Um último valor antigo precisa ser avaliado junto ao timestamp de coleta.

## Tráfego VTI

Dois painéis Time series: entrada e saída, itens SNMP correspondentes a cada interface inventariada. Unidade bits/s; manter cor de cada VPN igual nos dois painéis. Mostrar último valor e máximo na legenda.

```text
^Interface \[(ipsec\d+)\(.*\)\]: Bits (received|sent)$
```

Substituir por `$1` remove o texto excedente. Depois usar nomes amigáveis por interface via overrides ou transformações individuais. Restringir consulta ao inventário desejado; não incluir toda interface IPsec sem verificar correspondência.

Bits/s é taxa de tráfego observada, não capacidade contratada. Não aplicar multiplicação por oito a itens que já fornecem bits/s. VPNs baseadas em políticas precisam de outra estratégia de tráfego, ainda fora desta etapa.

## Conectividade

Tabelas por unidade ou destino com ping, perda e tempo de resposta. Para séries nomeadas `<destino>: ICMP <métrica>`, usar redução para último valor, extração de nome/métrica e agrupamento em matriz. Perda em percentual 0–100; latência em segundos com apresentação automática em ms. Resposta zero durante falha de ping não deve parecer latência excelente.

## Saúde

Stat de sucesso: `1=COLETA OK`, `0=ERRO NA COLETA`. Outro Stat para horário: converter Unix segundos para milissegundos antes de formatar como data/hora no Grafana. Multiplicar por `1000` somente uma vez.

Para No data: conferir janela de tempo, presença de histórico numérico no Zabbix, filtros, host técnico, retorno do Query inspector e cache do datasource. Refresh do dashboard não executa o coletor no firewall.
