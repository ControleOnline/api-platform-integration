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
- Correcao financeira de marketplace deve explicitar `payer`, `receiver`, `wallet`, `paymentType` e `description` em cada invoice gerada a partir do resumo da integracao.
- Recebimentos vindos de marketplace para `Food99` e `iFood` devem ser agrupados em uma invoice semanal unica, com vencimento na quarta-feira e vinculacao por `order_invoice` para todos os pedidos incluidos no repasse.
