## Escopo
- Clients de HTTP/autenticacao usados pelas integracoes de marketplace.

## Regras
- `IfoodClient` e `Food99Client` sao os unicos pontos permitidos de consulta HTTP e autenticacao para seus respectivos providers.
- Services de marketplace nao devem ler `OAUTH_*` diretamente nem chamar `HttpClientInterface` para iFood ou 99Food.
- Os clients sao responsaveis por cache de token, fallback de ambiente e logging de request/response; services de capacidade so orquestram e interpretam o retorno.
- Nao introduzir novos acessos HTTP diretos em services de integracao quando houver client dedicado.
