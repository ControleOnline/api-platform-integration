## Escopo
- Contratos e registry dos providers de marketplace.

## Regras
- `MarketplaceProviderRegistry` resolve handlers, estados e snapshots por contrato; callers nao devem concatenar nome de classe.
- `AbstractMarketplaceService` guarda o bootstrap comum e os utilitarios compartilhados entre providers.
- Novas consultas persistentes devem ficar em repositorios ou resolvers dedicados; helpers compartilhados devem virar classes auxiliares; services aqui so orquestram.
- `IfoodStoreOperationsService` concentra store/admin e estado operacional da loja; `IfoodCatalogOperationsService` concentra catalogo/menu; nao voltar a misturar esses blocos no mesmo arquivo.
- `IfoodPeopleOperationsService`, `IfoodFinancialOperationsService` e `IfoodOrderOperationsService` existem para isolar responsabilidades do iFood; nao voltar a juntar pessoas, financeiro e pedido no mesmo service.
- `Food99CatalogOperationsService`, `Food99PeopleOperationsService`, `Food99FinancialOperationsService` e `Food99OrderOperationsService` existem para isolar responsabilidades; nao voltar a juntar catalogo, pessoas, financeiro e pedido no mesmo service.
