## Escopo
- Contratos e registry dos providers de marketplace.

## Regras
- `MarketplaceProviderRegistry` resolve handlers, estados e snapshots por contrato; callers nao devem concatenar nome de classe.
- `AbstractMarketplaceService` guarda o bootstrap comum e os utilitarios compartilhados entre providers.
- Novas consultas persistentes devem ficar em repositorios ou resolvers dedicados; helpers compartilhados devem virar classes auxiliares; services aqui so orquestram.
- `IfoodStoreOperationsService` concentra store/admin e estado operacional da loja; `IfoodCatalogOperationsService` concentra catalogo/menu; nao voltar a misturar esses blocos no mesmo arquivo.
- `IfoodPeopleOperationsService`, `IfoodFinancialOperationsService` e `IfoodOrderOperationsService` existem para isolar responsabilidades do iFood; nao voltar a juntar pessoas, financeiro e pedido no mesmo service.
- `Food99CatalogOperationsService`, `Food99PeopleOperationsService`, `Food99FinancialOperationsService` e `Food99OrderOperationsService` existem para isolar responsabilidades; nao voltar a juntar catalogo, pessoas, financeiro e pedido no mesmo service.
- `Food99StoreOperationsService` e o dono do catalogo de cancelamento da loja; `Food99Service` nao deve reter a lista `SHOP_CANCEL_REASONS` nem outras constantes de tarifa/settlement.
- `Food99OrderOperationsService` deve expor os forwards de acao esperados por `changeStatus` (`performReadyAction`, `performCancelAction`, `performDeliveredAction`) para nao depender de metodos inexistentes no service principal.
- `Food99FinancialOperationsService` nao deve calcular comissoes, logisticas ou settlement a partir de `price` e `promotions`; ele so materializa as seções ja recebidas no JSON salvo (`financial`, `payment`, `customer`, `address`, `notes`, `identifiers`).
- Acesso a `extra_data` deve usar `ExtraDataService`; nao reimplementar leitura/escrita por queries diretas ou reflection entre `iFoodService`/`Food99Service` e as classes de capacidade.
- Writers de `extra_data` desta camada devem ignorar valores vazios e preencher `source` com o app canonico da integracao. Se nao houver valor util, nada deve ser persistido.
- `extra_data` e `extra_fields` nao podem guardar snapshot rico, pessoa, pedido, financeiro ou configuracao quando houver destino canonico no dominio. IDs e codigos remotos sao o unico uso aceito; o restante deve ser materializado e depois limpo.
