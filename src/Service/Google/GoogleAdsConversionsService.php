<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\PeopleRoleService;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Offline conversion uploads for the gclid attribution pipeline (#67 in api-platform-people).
 * Each conversion carries { gclid, conversionAction, conversionDateTime, conversionValue, currencyCode }.
 */
final class GoogleAdsConversionsService
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

    /**
     * @param list<array{gclid:string,conversionAction:string,conversionDateTime:string,conversionValue?:float,currencyCode?:string}> $conversions
     */
    public function uploadConversions(People $people, array $conversions): array
    {
        if ($conversions === []) {
            return ['success' => true, 'uploaded' => 0];
        }

        $customer = $this->customerResolver->customerFor($people);
        $accessToken = $this->clientFactory->accessTokenFor($people, GoogleMarketingScopes::SET_ADS);
        $developerToken = $this->customerResolver->developerToken($this->peopleRoleService->getMainCompany());

        $operations = [];
        foreach ($conversions as $c) {
            $operations[] = array_filter([
                'gclid' => (string) $c['gclid'],
                'conversionAction' => (string) $c['conversionAction'],
                'conversionDateTime' => (string) $c['conversionDateTime'],
                'conversionValue' => isset($c['conversionValue']) ? (float) $c['conversionValue'] : null,
                'currencyCode' => isset($c['currencyCode']) ? (string) $c['currencyCode'] : null,
            ], static fn ($v) => $v !== null && $v !== '');
        }

        $url = self::BASE_URL . '/' . self::API_VERSION . '/customers/' . $customer['customer_id'] . ':uploadClickConversions';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'developer-token' => $developerToken,
                    'login-customer-id' => $customer['login_customer_id'],
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'conversions' => $operations,
                    'partialFailure' => true,
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
            'uploaded' => count($body['results'] ?? []),
            'results' => $body['results'] ?? [],
            'partial_failure' => $body['partialFailureError'] ?? null,
        ];
    }
}
