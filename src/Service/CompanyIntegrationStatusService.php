<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\People;

class CompanyIntegrationStatusService
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly LinkedInService $linkedInService,
    ) {
    }

    public function listCompanyIntegrations(People $provider): array
    {
        return [
            $this->buildIntegrationStatus(
                'uber',
                'Uber',
                [
                    'OAUTH_UBER_STORE_ID',
                ],
                $provider
            ),
            $this->buildIntegrationStatus(
                'asaas',
                'Asaas',
                [
                    'asaas-key',
                    'asaas-receiver-pix-key',
                    'asaas-webhook-token',
                ],
                $provider
            ),
            $this->buildIntegrationStatus(
                'clicksign',
                'ClickSign',
                [
                    'clicksign-key',
                ],
                $provider
            ),
            $this->buildLinkedInStatus($provider),
        ];
    }

    private function buildLinkedInStatus(People $provider): array
    {
        $status = $this->linkedInService->getConnectionStatus($provider);

        return [
            'key' => 'linkedin',
            'label' => 'LinkedIn',
            'connected' => !empty($status['connected']),
            'member_id' => $status['member_id'] ?? null,
            'member_name' => $status['member_name'] ?? null,
            'client_configured' => !empty($status['client_configured']),
            'required_config_keys' => [],
        ];
    }

    private function buildIntegrationStatus(
        string $key,
        string $label,
        array $requiredConfigKeys,
        People $provider
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'connected' => $this->hasRequiredConfigs($provider, $requiredConfigKeys),
            'required_config_keys' => $requiredConfigKeys,
        ];
    }

    private function hasRequiredConfigs(People $provider, array $requiredConfigKeys): bool
    {
        foreach ($requiredConfigKeys as $configKey) {
            $value = trim((string) ($this->configService->getConfig($provider, $configKey) ?? ''));

            if ($value === '') {
                return false;
            }
        }

        return true;
    }
}
