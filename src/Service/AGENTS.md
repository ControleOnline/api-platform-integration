## Escopo
- Regras operacionais especificas dos services de integracao de marketplace.

## Food99Service.php
- A carteira operacional da integracao deve ser `99 Food`, mesmo quando o app canonico continuar sendo `Food99`.
- `Food99Service` deve ficar como fachada fina; nao reintroduzir catalogo de cancelamento ou calculos de settlement no service principal.
- `Food99FinancialOperationsService` nao deve calcular comissoes, logisticas ou settlement a partir de `price` e `promotions`; ele apenas materializa as seções ja gravadas no JSON (`financial`, `payment`, `customer`, `address`, `notes`, `identifiers`) ou outras seções ja persistidas.
- O vinculo do cliente com o 99 deve usar apenas `receive_address.uid` como identificador remoto oficial.
- Nao tentar recuperar `Food99.code` por telefone, e-mail, nome parcial ou outros heuristics.
- Se o webhook legarado nao trouxer `uid`, o service pode tentar `nome + endereco completo` apenas para reconciliacao exata e unica de registros antigos.
- Quando nao houver `uid` nem match exato de legado, o service deve seguir sem código e nao criar identificador falso.
- Pedidos novos do evento `orderNew` devem gerar o financeiro automaticamente no proprio pipeline de integracao. O endpoint manual de invoices serve apenas para backfill/legado.
- `Food99Service` nao deve criar invoices inline ao processar o webhook; o pedido principal precisa ser persistido com `otherInformations` e o financeiro deve ser reconstruido pelo gerador central. O pedido filho de logistica nao deve espelhar esse snapshot.
- Para `Food99`, o snapshot financeiro deve vir somente de `order.otherInformations.Food99`; o contexto legado `iFood` nao pode alimentar conta, wallet ou `paymentType`.
- Quando o pedido for pago no app, precisa existir uma invoice explicita do fluxo `cliente -> 99 Food`.
- Os meios de pagamento do cliente devem ficar apenas na invoice; nao espelhar esses canais na wallet operacional do `99 Food`.
- O pagamento do motoboy em pedidos de entrega pela plataforma deve ser modelado com `payer = 99 Food` e `receiver = motoboy`, criando a pessoa do entregador no sistema quando houver identificacao suficiente.
- Descontos subsidiados pela loja e pela plataforma que ja chegam compensados no repasse devem aparecer como ajustes internos do marketplace, com `payer = 99 Food` e `receiver = 99 Food`, sem criar conta separada da loja por esse desconto.
- Em `Food99`, o valor que a loja recebe do marketplace deve entrar na invoice semanal unica, com vencimento na quarta-feira seguinte ao fechamento. Em `iFood`, a mesma invoice semanal fecha por semana e vence um mes depois do fechamento. Taxas cobradas da loja ficam em invoices especificas; descontos ja liquidados dentro do repasse seguem a regra de ajuste interno acima.
- Em `Food99`, o receiver da invoice de repasse/cobranca precisa ser sempre `99 Food`, obtido diretamente do cadastro da marca, sem reaproveitar `iFood` nem estado estatico compartilhado.
- Em `Food99`, o vencimento da invoice semanal segue o fechamento de segunda a domingo e cai na quarta-feira seguinte.
- Em `Food99`, pedidos `canceled`/`cancelled` nao devem recriar financeiro; o backfill deve apenas limpar invoices gerenciadas legadas daquele pedido.
- Em `Food99`, o financeiro deve ser materializado apenas a partir dos dados persistidos no snapshot; nao recalcular settlement, comissoes ou taxas a partir de campos brutos do webhook.
- Em `Food99`, a carteira de repasse da loja vem da tela de integracao e precisa ser persistida como `store_settlement_wallet_id`; essa carteira e a unica fonte valida para `provider_wallet`.
- Em pedidos filhos de logistica gerados por `Food99`, `provider` e o motoboy, `payer` e `99 Food`, `client` e a empresa do pedido pai, `deliveryContact` e o cliente do pedido pai, `addressOrigin` deve estar sempre preenchido e o filho nao deve copiar `otherInformations`.
- Acesso a `extra_data` nao deve ser reimplementado em services de marketplace; use `ExtraDataService` para leitura e escrita de chaves persistidas.

## iFoodService.php
- A carteira operacional da integracao deve ser `iFood`.
- Quando o pedido for pago no app, precisa existir uma invoice explicita do fluxo `cliente -> iFood`.
- Descontos e ajustes que ja venham liquidos no repasse, sem cobranca separada para a loja, devem ser modelados como fluxo interno do marketplace, com `payer = iFood` e `receiver = iFood`.
- O valor que a loja recebe do marketplace deve entrar na invoice semanal unica, com vencimento na quarta-feira. Taxas cobradas da loja ficam em invoices especificas; descontos ja liquidados dentro do repasse seguem a regra de ajuste interno acima.
- Quando o pedido for pago na entrega, o recebimento do cliente continua separado do repasse semanal do marketplace.
- Cotacao e solicitacao de entrega devem falhar quando pickup e dropoff forem o mesmo endereco ou quando o dropoff nao resolver um endereco real e completo.
- Eventos `HANDSHAKE_DISPUTE` devem persistir os campos essenciais da disputa antes de qualquer acao: `disputeId`, `action`, `handshakeType`, `handshakeGroup`, `message`, `expiresAt`, `timeoutAction`, `acceptCancellationReasons`, `alternatives` e `evidences`.
- A resposta de disputa deve usar `disputeId`, nao `orderId`. Aceite, rejeicao e contraproposta sao endpoints de `/order/v1.0/disputes/{disputeId}`.
- Para `reject`, enviar sempre `reason` valido de `negotiationReasons`; se o usuario nao escolheu motivo, nao enviar fallback silencioso que possa reprovar a solicitacao sem clareza operacional.
- Para `accept`, quando `acceptCancellationReasons` vier preenchido, enviar um desses motivos. Se a lista vier vazia, aceitar sem body ou com o motivo explicitamente escolhido conforme a API permitir.
- Para `alternative` com `REFUND` ou `BENEFIT`, o payload so e valido com `metadata.amount.value` e `metadata.amount.currency`. Para `ADDITIONAL_TIME`, o payload so e valido com `metadata.additionalTimeInMinutes` e `metadata.reason`.
- O endpoint de `alternative` da documentacao atual e `/order/v1.0/disputes/{disputeId}/alternative`; qualquer uso de `alternativeId` deve ser validado contra a referencia oficial antes de alterar o client.
- `HANDSHAKE_SETTLEMENT` deve fechar a disputa localmente. A UI nao deve continuar oferecendo acoes depois de `ACCEPTED`, `REJECTED`, `EXPIRED` ou `ALTERNATIVE_REPLIED`.
- Cancelamento normal da loja continua separado da plataforma de negociacao: buscar `/orders/{id}/cancellationReasons` e solicitar `/orders/{id}/requestCancellation` com `{ "reason": "codigo" }`.

## Marketplace contracts
- `AbstractMarketplaceService` concentra bootstrap e utilitarios comuns dos providers de marketplace.
- `MarketplaceProviderRegistry` e as interfaces de capability resolvem handlers, estados e snapshots por contrato; callers nao devem concatenar nomes de classe.
- Consultas novas devem ficar em repositórios ou resolvers dedicados; helpers compartilhados devem virar classes de apoio, nao metodo solto em service.
- O bloco grande de marketplace foi separado por capacidade em classes dedicadas em `Service/Marketplace`; nao reintroduzir catalogo, people, financeiro, pedido e store/admin no mesmo arquivo.
- `iFoodService` deve permanecer como orquestrador das classes `IfoodStoreOperationsService`, `IfoodCatalogOperationsService`, `IfoodPeopleOperationsService`, `IfoodFinancialOperationsService` e `IfoodOrderOperationsService`; o service principal nao deve voltar a concentrar esses blocos.
- `Food99Service` deve permanecer como orquestrador das classes `Food99StoreOperationsService`, `Food99CatalogOperationsService`, `Food99PeopleOperationsService`, `Food99FinancialOperationsService` e `Food99OrderOperationsService`; as responsabilidades nao devem voltar a se misturar no mesmo arquivo.
- `iFoodService` segue a mesma separacao por capacidades, mas com cadencia financeira propria: fechamento semanal e vencimento um mes depois do fechamento para a invoice de repasse.
