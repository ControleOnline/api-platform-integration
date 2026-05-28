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
- Recebimentos vindos de marketplace devem ser agrupados em uma invoice semanal unica e vinculados por `order_invoice` aos pedidos incluidos no repasse.
- Em `Food99`, a invoice de repasse/cobranca deve usar `receiver = 99 Food`, nunca `iFood` ou estado compartilhado do fluxo legado, e o vencimento e na quarta-feira seguinte ao fechamento semanal.
- Em `iFood`, a invoice de repasse/cobranca deve usar `receiver = iFood`, com fechamento semanal e vencimento um mes depois do fechamento, sem compartilhar o fluxo de `Food99`.
- `Food99Service` deve permanecer como fachada fina; catalogo de cancelamento e outros detalhes operacionais devem viver nos services de capacidade (`Food99FinancialOperationsService` e `Food99StoreOperationsService`).
- `Food99FinancialOperationsService` deve tratar o JSON como fonte canônica e apenas materializar os blocos já gravados; nao calcular comissoes, logisticas, settlement ou percentuais a partir de `price`/`promotions` ou outros campos brutos.
- `IfoodClient` e `Food99Client` sao os unicos pontos de consulta HTTP/autenticacao do iFood e do 99Food no modulo; os services de capacidade nao devem repetir leitura de credenciais nem consultar esses providers diretamente fora deles.
- Em `Food99`, a carteira de repasse da loja vem somente de `store_settlement_wallet_id` configurado na tela de integracao; `provider_wallet` nao pode ser inferido nem cair em `99 Food`.
- Em `Food99`, a geracao financeira deve ler somente `order.otherInformations.Food99`; o legado `iFood` nao pode ser usado para criar ou recriar invoices.
- Em `Food99`, pedidos de segunda a domingo entram na mesma invoice semanal, com vencimento na quarta-feira seguinte ao fechamento.
- Em `Food99`, pedidos novos do webhook `orderNew` devem gerar esse financeiro automaticamente no pipeline de integracao. O endpoint manual de invoices fica como backfill para pedidos legados.
- Em `Food99`, pedidos com status terminal `canceled`/`cancelled` devem ser tratados como sem geracao financeira, mas a limpeza das invoices gerenciadas do proprio pedido continua obrigatoria.
- Em `Food99`, os valores financeiros devem ser lidos apenas dos campos já materializados no snapshot salvo; nao recalcular comissao, logistica, settlement ou qualquer percentual a partir de `price`, `promotions` ou outros campos brutos.
- Em pedidos filhos de logistica gerados pela integracao `Food99`, `provider` e o motoboy, `payer` e `99 Food`, `client` e a empresa do pedido pai, `deliveryContact` e o cliente do pedido pai, `addressOrigin` deve estar sempre preenchido e o filho nao deve copiar `otherInformations`.
- `extra_data` e `extra_fields` nesta camada so podem guardar IDs, chaves remotas e codigos que nao tenham destino materializado equivalente. Snapshot rico, pessoas, pedidos, financeiro, logistica e configuracoes devem ir para as tabelas/JSON canonicos do dominio dono e devem ser limpos do legado assim que o backfill confirmar a materializacao.
- Writers de marketplace devem persistir apenas IDs e codigos validos em `extra_data`; se nao houver valor util, a escrita deve ser ignorada.
- Quando a escrita vier de `Food99` ou `iFood`, `source` deve ser gravado com o app canonico correspondente. Nao gravar bindings novos com `source` nulo.
- Alertas humanos do `MANAGER` devem usar `queue_name = PushNotification` e FCM direto; nao criar alerta humano novo como `Websocket`.
- `Websocket` e `PushNotification` sao filas efemeras: item entregue deve ser removido da tabela `integration`, e qualquer registro remanescente com mais de 24 horas deve ser removido pela manutencao.
- O canal Android de FCM do `MANAGER` usa o som nativo `caixa.m4a` empacotado; URL de audio configurada nao toca quando o push chega com app fechado.

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
