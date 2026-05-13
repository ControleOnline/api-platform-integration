## Escopo
- Regras operacionais especificas dos services de integracao de marketplace.

## Food99Service.php
- A carteira operacional da integracao deve ser `99 Food`, mesmo quando o app canonico continuar sendo `Food99`.
- O vinculo do cliente com o 99 deve usar apenas `receive_address.uid` como identificador remoto oficial.
- Nao tentar recuperar `Food99.code` por telefone, e-mail, nome parcial ou outros heuristics.
- Se o webhook legarado nao trouxer `uid`, o service pode tentar `nome + endereco completo` apenas para reconciliacao exata e unica de registros antigos.
- Quando nao houver `uid` nem match exato de legado, o service deve seguir sem código e nao criar identificador falso.
- Pedidos novos do evento `orderNew` devem gerar o financeiro automaticamente no proprio pipeline de integracao. O endpoint manual de invoices serve apenas para backfill/legado.
- Quando o pedido for pago no app, precisa existir uma invoice explicita do fluxo `cliente -> 99 Food`.
- O pagamento do motoboy em pedidos de entrega pela plataforma deve ser modelado com `payer = 99 Food` e `receiver = motoboy`, criando a pessoa do entregador no sistema quando houver identificacao suficiente.
- Descontos subsidiados pela loja e pela plataforma que ja chegam compensados no repasse devem aparecer como ajustes internos do marketplace, com `payer = 99 Food` e `receiver = 99 Food`, sem criar conta separada da loja por esse desconto.
- O valor que a loja recebe do marketplace deve entrar na invoice semanal unica, com vencimento na quarta-feira. Taxas cobradas da loja ficam em invoices especificas; descontos ja liquidados dentro do repasse seguem a regra de ajuste interno acima.
- Em `Food99`, o receiver da invoice de repasse/cobranca precisa ser sempre `99 Food`, obtido diretamente do cadastro da marca, sem reaproveitar `iFood` nem estado estatico compartilhado.
- Em `Food99`, o vencimento da invoice semanal segue o fechamento de segunda a domingo e cai na quarta-feira seguinte.
- Em `Food99`, pedidos `canceled`/`cancelled` nao devem recriar financeiro; o backfill deve apenas limpar invoices gerenciadas legadas daquele pedido.
- Em `Food99`, o weekly settlement deve considerar `service_fee` do payload bruto e a taxa logistica calibrada da integracao, nao a heuristica antiga de 60% com piso de R$ 4,50.

## iFoodService.php
- A carteira operacional da integracao deve ser `iFood`.
- Quando o pedido for pago no app, precisa existir uma invoice explicita do fluxo `cliente -> iFood`.
- Descontos e ajustes que ja venham liquidos no repasse, sem cobranca separada para a loja, devem ser modelados como fluxo interno do marketplace, com `payer = iFood` e `receiver = iFood`.
- O valor que a loja recebe do marketplace deve entrar na invoice semanal unica, com vencimento na quarta-feira. Taxas cobradas da loja ficam em invoices especificas; descontos ja liquidados dentro do repasse seguem a regra de ajuste interno acima.
- Quando o pedido for pago na entrega, o recebimento do cliente continua separado do repasse semanal do marketplace.
- Eventos `HANDSHAKE_DISPUTE` devem persistir os campos essenciais da disputa antes de qualquer acao: `disputeId`, `action`, `handshakeType`, `handshakeGroup`, `message`, `expiresAt`, `timeoutAction`, `acceptCancellationReasons`, `alternatives` e `evidences`.
- A resposta de disputa deve usar `disputeId`, nao `orderId`. Aceite, rejeicao e contraproposta sao endpoints de `/order/v1.0/disputes/{disputeId}`.
- Para `reject`, enviar sempre `reason` valido de `negotiationReasons`; se o usuario nao escolheu motivo, nao enviar fallback silencioso que possa reprovar a solicitacao sem clareza operacional.
- Para `accept`, quando `acceptCancellationReasons` vier preenchido, enviar um desses motivos. Se a lista vier vazia, aceitar sem body ou com o motivo explicitamente escolhido conforme a API permitir.
- Para `alternative` com `REFUND` ou `BENEFIT`, o payload so e valido com `metadata.amount.value` e `metadata.amount.currency`. Para `ADDITIONAL_TIME`, o payload so e valido com `metadata.additionalTimeInMinutes` e `metadata.reason`.
- O endpoint de `alternative` da documentacao atual e `/order/v1.0/disputes/{disputeId}/alternative`; qualquer uso de `alternativeId` deve ser validado contra a referencia oficial antes de alterar o client.
- `HANDSHAKE_SETTLEMENT` deve fechar a disputa localmente. A UI nao deve continuar oferecendo acoes depois de `ACCEPTED`, `REJECTED`, `EXPIRED` ou `ALTERNATIVE_REPLIED`.
- Cancelamento normal da loja continua separado da plataforma de negociacao: buscar `/orders/{id}/cancellationReasons` e solicitar `/orders/{id}/requestCancellation` com `{ "reason": "codigo" }`.
