# Conversas com clientes: WhatsApp (oficial/QR) e e-mail

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

## Roteamento (todas as conexões)

`CustomerConversationRouter` recebe mensagens de **qualquer** conexão:

1. empresa com IA configurada (`ai-providers`): a mensagem entra na conversa aberta do cliente
   (`ia|relationship|support`) ou abre uma `ia` com `tasks.ai_agent = auto`; enquanto houver IA
   atribuída, um job `McpChat` (canal customer) é enfileirado;
2. sem IA: segue `connections.type` (support/relationship), como antes.

A conversa guarda o destino e `conn:{id}` em `announce`; a resposta sai pela mesma conexão
(`WhatsApp` ou `EmailOutbound`). Funcionários acompanham e respondem no CRM / Suporte.

## E-mail

`connections.channel = email`, `channel_config`:
`imap_host, imap_port (993), imap_encryption (ssl|none), imap_mailbox (INBOX), username, password,
smtp_dsn | smtp_host, smtp_port, smtp_encryption (tls|ssl), from_address, from_name`.

- Leitura: comando `email:fetch` (cadastrar no cron, ex. a cada 2 min) → fila `EmailInbound`
  (dedupe por Message-ID; ignora e-mails da própria caixa; remove citações da resposta).
- Resposta: fila `EmailOutbound` via SMTP da conexão, assunto `Re: {nome da conversa}`.
