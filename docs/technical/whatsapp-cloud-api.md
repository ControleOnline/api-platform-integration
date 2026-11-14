# WhatsApp Cloud API (oficial Meta)

Issue: ControleOnline/api-platform-integration#66 · Parent: ControleOnline/app-community#1335

## Fluxo

```
Meta ──POST /webhook/whatsapp-cloud──▶ integration (queue WhatsAppCloud, dedupe por wamid)
       └─ WhatsAppCloudService ─▶ connections (channel=whatsapp_official, channel_config.phone_number_id)
             ├─ type=ia            ─▶ Task ia + TaskInteration pública ─▶ integration (queue McpChat)
             │                         └─ IA responde (TaskInteration pública) ─▶ notifyClient ─▶ queue WhatsApp ─▶ Graph API
             │                         └─ handoff_to_human ─▶ Task vira relationship (CRM) ou support (analista)
             └─ type=support|relationship ─▶ fluxo humano existente
```

Toda mensagem recebida também gera um evento websocket `conversation_message`
para os devices `MANAGER` da empresa (tela `/ai`).

## Configuração

| Variável | Uso |
| --- | --- |
| `WHATSAPP_CLOUD_VERIFY_TOKEN` | Token livre informado no app Meta (verificação GET `hub.challenge`) |
| `WHATSAPP_CLOUD_APP_SECRET` | App Secret do app Meta; valida `X-Hub-Signature-256`. Sem ele, todo POST é recusado |

No app Meta: produto WhatsApp → Configuração → Webhook
`https://api.controleonline.com/webhook/whatsapp-cloud`, campo assinado `messages`.

Na tabela `connections` (tela Conexões): `channel=whatsapp_official`, `type=ia`,
`channel_config` = `{"access_token","phone_number_id","api_version","ai_provider"?}`.
O telefone da conexão é preenchido automaticamente na primeira mensagem
(`display_phone_number`), pois a resposta usa `connection.phone` como origem.
