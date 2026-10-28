# Configuração manual no Zabbix

Exemplo para host fictício `vpn-lab`. Campos abaixo são receita manual, não template de importação.

## Item mestre

Nome: `pfSense: Coleta IPsec`  
Tipo: Zabbix trapper  
Chave: `pfsense.ipsec.raw`  
Tipo de informação: Texto

Restringir hosts permitidos ao emissor autorizado. O nome técnico do host no envio deve corresponder exatamente ao cadastrado.

## Descoberta dependente

Nome: `pfSense: Descoberta de VPNs IPsec`  
Chave: `pfsense.ipsec.discovery`  
Item mestre: `pfsense.ipsec.raw`  
Pré-processamento JSONPath: `$.discovery`

Em erro de pré-processamento, descartar valor. Para falhas conhecidas de coleta, aplicar validação anterior que descarte a descoberta quando `collection_ok` for diferente de 1; não tratar uma falha do coletor como remoção do inventário. Definir retenção dos recursos perdidos conforme operação.

## Protótipo de estado

Nome: `{#UNIT} {#PATH}: Estado da VPN`  
Chave: `pfsense.ipsec.tunnel.up[{#IFNAME}]`  
Tipo: Item dependente  
Mestre: `pfsense.ipsec.raw`  
Informação: Numérico sem sinal  
JSONPath: `$.tunnels["{#IFNAME}"].session_up`

Descartar valor quando JSONPath não existir. Mapeamento: `0=DOWN`, `1=UP`, `2=PARCIAL`.

Tags: `component=vpn`, `unit={#UNIT}`, `path={#PATH}`, `interface={#IFNAME}`. Para VPN sem dois caminhos, usar `{#PATH}` vazio e ajustar nome de apresentação.

## Saúde da coleta

| Nome | Chave | JSONPath | Tipo / unidade |
| --- | --- | --- | --- |
| IPsec: Sucesso da coleta | `pfsense.ipsec.collection.ok` | `$.collection_ok` | Numérico sem sinal |
| IPsec: Horario da coleta | `pfsense.ipsec.collection.timestamp` | `$.collected_at` | Numérico sem sinal / `unixtime` |

Ambos são dependentes do mesmo mestre. Exemplo de expressão de alerta para host fictício:

```text
nodata(/vpn-lab/pfsense.ipsec.raw,3m)=1 or last(/vpn-lab/pfsense.ipsec.collection.ok)=0 or (now()-last(/vpn-lab/pfsense.ipsec.collection.timestamp))>180
```

Esse alerta identifica ausência, falha ou atraso. Não transforma automaticamente estado UP antigo em UNKNOWN. Exibir a saúde da coleta junto aos estados no dashboard.
