## Integration Module

- Public webhooks must never return 404 for provider callbacks that are already configured outside the system.
- Webhook controllers should authenticate and route provider-specific flows when a domain integration exists.
- When the provider-specific integration is not implemented yet, capture the raw payload in `integration` and return success so external providers stop retrying.
- Generic webhook captures use the `WebhookCapture` queue and must be closed by `WebhookCaptureService` without domain side effects.

## Qualidade de código

- A barra comum de modularizacao, testes, smoke tests e limite de tamanho de componentes vive em `https://github.com/ControleOnline/agents-mcp/blob/master/skills/shared/code-quality.md`.
