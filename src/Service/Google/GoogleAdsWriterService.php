<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\PeopleRoleService;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Ads mutations: pause/resume/update campaigns + budget changes.
 * Offline conversion uploads live in GoogleAdsConversionsService.
 */
final class GoogleAdsWriterService
{
    private const API_VERSION = 'v17';
    private const BASE_URL = 'https://googleads.googleapis.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleMarketingClientFactory $clientFactory,
        private readonly GoogleAdsCustomerResolver $customerResolver,
        private readonly PeopleRoleService $peopleRoleService,
    ) {
    }

    public function pauseCampaign(People $people, string $campaignId): array
    {
        return $this->mutateCampaign($people, $campaignId, ['status' => 'PAUSED']);
    }

    public function resumeCampaign(People $people, string $campaignId): array
    {
        return $this->mutateCampaign($people, $campaignId, ['status' => 'ENABLED']);
    }

    /**
     * @param array{status?:string,name?:string,endDate?:string,budget_micros?:int} $updates
     */
    public function updateCampaign(People $people, string $campaignId, array $updates): array
    {
        return $this->mutateCampaign($people, $campaignId, $updates);
    }

    private function mutateCampaign(People $people, string $campaignId, array $updates): array
    {
        $customer = $this->customerResolver->customerFor($people);
        $accessToken = $this->clientFactory->accessTokenFor($people, GoogleMarketingScopes::SET_ADS);
        $developerToken = $this->customerResolver->developerToken($this->peopleRoleService->getMainCompany());

        $resource = sprintf('customers/%s/campaigns/%s', $customer['customer_id'], $campaignId);
        $updateMask = [];
        $campaignPayload = ['resourceName' => $resource];

        foreach ($updates as $key => $value) {
            switch ($key) {
                case 'status':
                    $campaignPayload['status'] = (string) $value;
                    $updateMask[] = 'status';
                    break;
                case 'name':
                    $campaignPayload['name'] = (string) $value;
                    $updateMask[] = 'name';
                    break;
                case 'endDate':
                    $campaignPayload['endDate'] = (string) $value;
                    $updateMask[] = 'end_date';
                    break;
                case 'budget_micros':
                    // Budget sits on a different resource; caller should handle separately.
                    break;
            }
        }

        if ($updateMask === []) {
            return ['skipped' => true, 'reason' => 'no_valid_updates'];
        }

        $url = self::BASE_URL . '/' . self::API_VERSION . '/customers/' . $customer['customer_id'] . '/campaigns:mutate';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'developer-token' => $developerToken,
                    'login-customer-id' => $customer['login_customer_id'],
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'operations' => [[
                        'update' => $campaignPayload,
                        'updateMask' => implode(',', $updateMask),
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);
            $body = json_decode($response->getContent(false), true);
        } catch (HttpClientException $e) {
            throw new GoogleAdsRequestException($e->getMessage(), 0, $e);
        }

        if (!is_array($body) || isset($body['error'])) {
            throw new GoogleAdsRequestException((string) ($body['error']['message'] ?? 'Unknown Google Ads error'));
        }

        return [
            'success' => true,
            'results' => $body['results'] ?? [],
            'partial_failures' => $body['partialFailureError'] ?? null,
        ];
    }
}
