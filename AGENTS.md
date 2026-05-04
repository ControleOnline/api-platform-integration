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
- Geracao financeira de marketplace deve explicitar `payer`, `receiver`, `wallet`, `paymentType` e `description` em cada invoice gerada a partir do resumo da integracao.
- Pagamento online do cliente ao marketplace deve virar invoice explicita com `payer = cliente` e `receiver = marketplace`.
- Ajustes e descontos que ja nascem compensados dentro do repasse do marketplace, sem cobranca separada para a loja, devem ser modelados como fluxo interno do marketplace com `payer = marketplace` e `receiver = marketplace`.
- Recebimentos vindos de marketplace para `Food99` e `iFood` devem ser agrupados em uma invoice semanal unica, com vencimento na quarta-feira e vinculacao por `order_invoice` para todos os pedidos incluidos no repasse.
- Em `Food99`, pedidos novos do webhook `orderNew` devem gerar esse financeiro automaticamente no pipeline de integracao. O endpoint manual de invoices fica como backfill para pedidos legados.

## iFood - negociacao e cancelamento
- `HANDSHAKE_DISPUTE` nao cancela o pedido sozinho. Ele abre uma disputa que precisa ficar visivel para decisao humana ate chegar `HANDSHAKE_SETTLEMENT` ou ate uma resposta local bem-sucedida.
- A disputa deve expor `disputeId`, `action`, `handshakeType`, `handshakeGroup`, `message`, `expiresAt`, `timeoutAction`, `acceptCancellationReasons`, `alternatives` e `evidences` sempre que vierem no evento.
- `action=CANCELLATION` representa cancelamento total; `action=PARTIAL_CANCELLATION` representa cancelamento parcial; `action=PROPOSED_AMOUNT_REFUND` representa cancelamento com proposta de reembolso.
- `timeoutAction=ACCEPT_CANCELLATION` cancela automaticamente ao expirar; `REJECT_CANCELLATION` rejeita automaticamente; `VOID` apenas encerra a disputa como expirada.
- Responder disputa usa exclusivamente `/order/v1.0/disputes/{disputeId}/accept`, `/reject` ou `/alternative`. Nao misturar com `/orders/{id}/requestCancellation`, que e outro fluxo.
- `/reject` deve enviar `reason` obrigatorio com valor valido de `negotiationReasons`: `HIGH_STORE_DEMAND`, `UNKNOWN_ISSUE`, `CUSTOMER_SATISFACTION`, `INVENTORY_CHECK`, `SYSTEM_ISSUE`, `WRONG_ORDER`, `PRODUCT_QUALITY`, `LATE_DELIVERY` ou `CUSTOMER_REQUEST`.
- `/accept` pode exigir `reason` quando `acceptCancellationReasons` vier preenchido; nesse caso usar um motivo da propria lista recebida no evento.
- `/alternative` so deve ser oferecido quando a alternativa recebida permitir montar payload completo. Para `REFUND` ou `BENEFIT`, precisa enviar `type` e `metadata.amount.value/currency`; para `ADDITIONAL_TIME`, precisa enviar `type`, `metadata.additionalTimeInMinutes` e `metadata.reason`.
- Se `alternative.type=REFUND` vier sem `metadata.maxAmount` ou valor monetario equivalente, o sistema nao deve tentar enviar contraproposta automatica. A UI deve bloquear/explicar que falta valor permitido pelo iFood.
- `HANDSHAKE_SETTLEMENT` fecha a disputa e deve atualizar o estado local com `status` (`ACCEPTED`, `REJECTED`, `EXPIRED`, `ALTERNATIVE_REPLIED`), `reason` e `selectedDisputeAlternative` quando existir.
- Cancelamento iniciado pela loja usa `GET /orders/{id}/cancellationReasons` para listar motivos e `POST /orders/{id}/requestCancellation` com body `{ "reason": "codigo" }`. O resultado final vem depois via evento `CANCELLED` ou `CANCELLATION_REQUEST_FAILED`.
