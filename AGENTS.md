## Escopo
- Modulo de integracoes externas.
- Centraliza conectores, webhooks, comandos e filas para Asaas, iFood, Food99, ClickSign, WhatsApp, N8N, Spotify, Bitcoin e outros provedores.

## Quando usar
- Prompts sobre webhook, sincronizacao externa, consumers, handlers, mensageria de integracao e adaptadores para terceiros.

## Limites
- A regra principal do dominio deve continuar no modulo dono, como `orders`, `financial` ou `contract`.
- `integration` deve traduzir e orquestrar comunicacao externa, nao se tornar dono da regra interna.
- Integracoes de marketplace devem escutar o fluxo principal do pedido via `onEntityChanged`/`order_action`, sem criar endpoints ou atalhos paralelos para trocar status.
- Use o nome canonico `Food99` ao identificar a integracao no backend.
- Em `Food99`, o codigo remoto oficial do cliente vem somente de `receive_address.uid`; nao usar telefone, e-mail ou outros campos para inventar vinculo.
- Registros legados sem `uid` podem ser reconciliados por `nome + endereco completo` somente quando a correspondencia for exata e unica no banco.
- Se nao houver `uid` nem correspondencia exata de legado, o sistema deve criar ou manter o cliente sem codigo, sem tentar derivar outro identificador.
- Geracao financeira de marketplace deve explicitar `payer`, `receiver`, `wallet`, `paymentType` e `description` em cada invoice gerada a partir do resumo da integracao.
- Pagamento online do cliente ao marketplace deve virar invoice explicita com `payer = cliente` e `receiver = marketplace`.
- Os canais de pagamento do cliente devem permanecer nas invoices; a wallet operacional do `99 Food` nao deve receber espelho desses canais.
- Ajustes e descontos que ja nascem compensados dentro do repasse do marketplace, sem cobranca separada para a loja, devem ser modelados como fluxo interno do marketplace com `payer = marketplace` e `receiver = marketplace`.
- Recebimentos vindos de marketplace para `Food99` e `iFood` devem ser agrupados em uma invoice semanal unica, com vencimento na quarta-feira e vinculacao por `order_invoice` para todos os pedidos incluidos no repasse.
- Em `Food99`, a invoice de repasse/cobranca deve usar `receiver = 99 Food`, nunca `iFood` ou estado compartilhado do fluxo legado.
- Em `Food99`, pedidos de segunda a domingo entram na mesma invoice semanal, com vencimento na quarta-feira seguinte ao fechamento.
- Em `Food99`, pedidos novos do webhook `orderNew` devem gerar esse financeiro automaticamente no pipeline de integracao. O endpoint manual de invoices fica como backfill para pedidos legados.
- Em `Food99`, pedidos com status terminal `canceled`/`cancelled` devem ser tratados como sem geracao financeira, mas a limpeza das invoices gerenciadas do proprio pedido continua obrigatoria.
- Em `Food99`, o repasse semanal deve abater `service_fee` do payload bruto e usar a taxa logistica calibrada pela integracao antes de fechar a invoice semanal. A calibracao vigente e `commission=7.9%`, `payment_processing=3.2%`, `logistics=60%` com piso de `R$ 4,50`.
- Em `Food99`, os componentes de fee calculados pela integracao devem ser arredondados normalmente em centavos; nao usar `ceil` para as taxas calculadas.

## iFood - negociacao e cancelamento
- `HANDSHAKE_DISPUTE` nao cancela o pedido sozinho. Ele abre uma disputa que precisa ficar visivel para decisao humana ate chegar `HANDSHAKE_SETTLEMENT` ou ate uma resposta local bem-sucedida.
- A disputa deve expor `disputeId`, `action`, `handshakeType`, `handshakeGroup`, `message`, `expiresAt`, `timeoutAction`, `acceptCancellationReasons`, `alternatives` e `evidences` sempre que vierem no evento.
- `action=CANCELLATION` representa cancelamento total; `action=PARTIAL_CANCELLATION` representa cancelamento parcial; `action=PROPOSED_AMOUNT_REFUND` representa cancelamento com proposta de reembolso.
- `timeoutAction=ACCEPT_CANCELLATION` cancela automaticamente ao expirar; `REJECT_CANCELLATION` rejeita automaticamente; `VOID` apenas encerra a disputa como expirada.
- Responder disputa usa exclusivamente `/order/v1.0/disputes/{disputeId}/accept`, `/reject` ou `/alternatives/{alternativeId}`. Nao misturar com `/orders/{id}/requestCancellation`, que e outro fluxo.
- `/reject` deve enviar `reason` obrigatorio com valor valido de `negotiationReasons`: `HIGH_STORE_DEMAND`, `UNKNOWN_ISSUE`, `CUSTOMER_SATISFACTION`, `INVENTORY_CHECK`, `SYSTEM_ISSUE`, `WRONG_ORDER`, `PRODUCT_QUALITY`, `LATE_DELIVERY` ou `CUSTOMER_REQUEST`.
- `/accept` pode exigir `reason` quando `acceptCancellationReasons` vier preenchido; nesse caso usar um motivo da propria lista recebida no evento.
- `/alternatives/{alternativeId}` so deve ser oferecido quando a alternativa recebida permitir montar payload completo. Para `REFUND` ou `BENEFIT`, precisa enviar `type` e `metadata.amount.value/currency`; para `ADDITIONAL_TIME`, precisa enviar `type`, `metadata.additionalTimeInMinutes` e `metadata.additionalTimeReason`.
- Se `alternative.type=REFUND` vier sem `metadata.maxAmount` ou valor monetario equivalente, o sistema nao deve tentar enviar contraproposta automatica. A UI deve bloquear/explicar que falta valor permitido pelo iFood.
- `HANDSHAKE_SETTLEMENT` fecha a disputa e deve atualizar o estado local com `status` (`ACCEPTED`, `REJECTED`, `EXPIRED`, `ALTERNATIVE_REPLIED`), `reason` e `selectedDisputeAlternative` quando existir.
- Cancelamento iniciado pela loja usa `GET /orders/{id}/cancellationReasons` para listar motivos e `POST /orders/{id}/requestCancellation`. Enviar o codigo selecionado tanto em `cancellationCode` quanto em `reason`, pois a documentacao atual cita `reason`, mas o endpoint em producao pode exigir `cancellationCode`. O resultado final vem depois via evento `CANCELLED` ou `CANCELLATION_REQUEST_FAILED`.
- `DELIVERY_DROP_CODE_REQUESTED` e `DELIVERY_DROP_CODE_VALIDATING` sao eventos de codigo/validacao de entrega, nao significam que a loja ja executou `readyToPickup` ou `dispatch`. Esses eventos nao podem bloquear o botao `Order Ready` quando o pedido local ainda esta em `preparing`.
