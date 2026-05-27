## Escopo
- Contratos e registry dos providers de marketplace.

## Regras
- `MarketplaceProviderRegistry` resolve handlers, estados e snapshots por contrato; callers nao devem concatenar nome de classe.
- `AbstractMarketplaceService` guarda o bootstrap comum e os utilitarios compartilhados entre providers.
- Novas consultas persistentes devem ficar em repositorios ou resolvers dedicados; services aqui so orquestram.
