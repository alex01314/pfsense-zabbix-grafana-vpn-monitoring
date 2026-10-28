# Arquitetura e decisões

## Fontes distintas para perguntas distintas

Estado do túnel vem do strongSwan. Tráfego das interfaces VTI vem de itens SNMP já existentes no Zabbix. Ping, perda e tempo de resposta vêm de verificações ICMP separadas. Não se infere estado BGP a partir de ping.

## Fluxo do coletor

1. Carregar inventário esperado com identificador estável, unidade e caminho.
2. Consultar `swanctl --list-sas --raw` e conferir retorno do processo.
3. Interpretar blocos aninhados com balanceamento de chaves. A saída não é JSON; uma regex que ignore a hierarquia pode associar um CHILD ao IKE errado.
4. Aceitar eventos `list-sa event` e o terminador `list-sas reply {}`.
5. Correlacionar VTI por `reqid`; correlacionar VPNs baseadas em políticas por conexão e nomes dos CHILDs esperados.
6. Considerar apenas CHILDs `INSTALLED` sob IKE `ESTABLISHED`. Deduplicar CHILDs lógicos durante rekey; IDs temporários não são chaves de inventário.
7. Produzir os estados e a descoberta a partir do inventário, inclusive quando um túnel esperado não possui SA ativa.
8. Escrever arquivo temporário e substituir o snapshot por rename no mesmo filesystem.

Em erro de consulta ou formato inesperado, emitir `collection_ok=0`. Na implementação documentada, o payload de erro não fornece novos estados de túnel; os itens dependentes devem descartar caminhos ausentes, e a saúde da coleta deve alertar sobre dados antigos.

## Dados auxiliares

Idade do IKE, idade do CHILD, identificadores e contadores ajudam no diagnóstico. Contadores de CHILD podem reiniciar na renegociação; calcular diferença sem tratar rekey produz taxas incorretas. O dashboard documentado utiliza tráfego SNMP para VTI. Tráfego das VPNs sem VTI não foi implementado nesta etapa.

## Envio

O processo de envio lê o snapshot e valida sua idade antes de encaminhá-lo ao item trapper. A implementação utilizou PHP para o protocolo Sender por ausência do executável `zabbix_sender`. O sucesso exige resposta de processamento, e não apenas conexão TCP aceita.

O envio pela porta TCP 10051 ocorre por rede controlada. Um cliente Sender simples em PHP não oferece TLS automaticamente: não expor esse fluxo diretamente à internet. Restringir origem no item trapper e no firewall; avaliar cliente com TLS quando necessário.

## Limites

- IKE e CHILD ativos não comprovam acesso a uma aplicação.
- Falta de tráfego não significa VPN indisponível.
- Um valor antigo UP pode permanecer no histórico após falha de coleta.
- Polling de um minuto pode não capturar quedas muito curtas.
- A descoberta acompanha o inventário configurado; não cadastra automaticamente toda VPN nova do firewall.
- A apresentação pública não inclui endereços ou quantidade de túneis do ambiente original.
