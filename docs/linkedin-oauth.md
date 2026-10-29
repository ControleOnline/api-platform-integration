# LinkedIn OAuth multi-tenant (Fase B)

**Issue:** ControleOnline/app-community#1199 (parent #1182)  
**Module:** `api-platform-integration`

## Endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/integrations/linkedin/authorization-page` | ROLE_HUMAN | Returns `authorization_url` + `state` |
| GET/POST | `/integrations/linkedin/status` | ROLE_HUMAN | Connection status (no secrets) |
| POST | `/integrations/linkedin/disconnect` | ROLE_HUMAN | Clears stored tokens for provider |
| GET | `/oauth/linkedin/return` | public | OAuth callback; redirects to `return_url` with query flags |

## Config keys (per People / company)

| Key | Role |
|-----|------|
| `linkedin_client_id` | App client id (already set on people/1) |
| `linkedin_client_secret` | App client secret |
| `linkedin-access-token` | Member access token |
| `linkedin-refresh-token` | Refresh token when provided |
| `linkedin-member-id` | OpenID `sub` |
| `linkedin-member-name` | Display name |
| `linkedin-expires-at` | Unix timestamp |
| `linkedin-scopes` | Granted scopes |

## Scopes (V1)

`openid profile email w_member_social`

## Isolation

- OAuth `state` is HMAC-signed and embeds `provider_id` + `app_domain`.
- Callback switches tenant DB via `DatabaseSwitchService` using the domain from state.
- Tokens are stored with `ConfigService` scoped to the provider People.

## Tests

```sh
php -d error_reporting=1 vendor/bin/phpunit --fail-on-notice \
  tests/Service/LinkedInClientTest.php \
  tests/Service/LinkedInServiceTest.php
```

Live OAuth requires a LinkedIn Developer app with redirect URI  
`https://{api-host}/oauth/linkedin/return`.
