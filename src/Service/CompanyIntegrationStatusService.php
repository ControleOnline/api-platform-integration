<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\People;

class CompanyIntegrationStatusService
{
    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public function listCompanyIntegrations(People $provider): array
    {
        return [
            $this->buildIntegrationStatus(
                'uber',
                'Uber',
                [
                    'OAUTH_UBER_APP_ID',
                    'OAUTH_UBER_CLIENT_SECRET',
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
