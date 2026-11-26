<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\PeopleRoleService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only Google Ads queries: list campaigns, run metrics reports.
 * Posts GAQL to googleAds:search. Mutation lives in GoogleAdsWriterService.
 */
final class GoogleAdsReaderService
{
    private const API_VERSION = 'v17';
    private const BASE_URL = 'https://googleads.googleapis.com';
    private const CACHE_TTL = 600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleMarketingClientFactory $clientFactory,
        private readonly GoogleAdsCustomerResolver $customerResolver,
        private readonly PeopleRoleService $peopleRoleService,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCampaigns(People $people): array
    {
        $query = 'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, '
            . 'campaign.bidding_strategy_type, campaign_budget.amount_micros FROM campaign ORDER BY campaign.name';

        return $this->mapCampaigns($this->search($people, $query, 'campaigns'));
    }

    /**
     * @param array{startDate?:string,endDate?:string,segment?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function runReport(People $people, array $filters = []): array
    {
        $start = $filters['startDate'] ?? date('Y-m-d', strtotime('-30 days'));
        $end = $filters['endDate'] ?? date('Y-m-d');
        $segment = $filters['segment'] ?? 'campaign';

        $query = sprintf(
            'SELECT campaign.id, campaign.name, %s, metrics.impressions, metrics.clicks, metrics.cost_micros, '
                . 'metrics.ctr, metrics.conversions, metrics.conversions_value FROM campaign '
                . "WHERE segments.date BETWEEN '%s' AND '%s' ORDER BY segments.date",
            $this->segmentField($segment),
            addslashes($start),
            addslashes($end),
        );

        return $this->mapReport($this->search($people, $query, 'report_' . $segment . '_' . $start . '_' . $end));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function search(People $people, string $query, string $cacheSuffix): array
    {
        $accessToken = $this->clientFactory->accessTokenFor($people, GoogleMarketingScopes::SET_ADS);
        $customer = $this->customerResolver->customerFor($people);
        $developerToken = $this->customerResolver->developerToken($this->peopleRoleService->getMainCompany());

        $cacheKey = 'ads.search.' . $people->getId() . '.' . sha1($customer['customer_id'] . '|' . $query . '|' . $cacheSuffix);
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $item->get();
        }

        $url = self::BASE_URL . '/' . self::API_VERSION . '/customers/' . $customer['customer_id'] . '/googleAds:search';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'developer-token' => $developerToken,
                    'login-customer-id' => $customer['login_customer_id'],
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(['query' => $query], JSON_THROW_ON_ERROR),
            ]);
            $body = json_decode($response->getContent(false), true);
        } catch (HttpClientException $e) {
            throw new GoogleAdsRequestException($e->getMessage(), 0, $e);
        }

        if (!is_array($body) || isset($body['error'])) {
            throw new GoogleAdsRequestException((string) ($body['error']['message'] ?? 'Unknown Google Ads error'));
        }

        $results = is_array($body['results'] ?? null) ? $body['results'] : [];
        $item->set($results);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $results;
    }

    private function segmentField(string $segment): string
    {
        return match ($segment) {
            'date' => 'segments.date',
            'device' => 'segments.device',
            default => 'campaign.id',
        };
    }

    /**
     * @param list<array<string,mixed>> $results
     * @return list<array<string,mixed>>
     */
    private function mapCampaigns(array $results): array
    {
        $out = [];
        foreach ($results as $row) {
            $campaign = $row['campaign'] ?? [];
            $budget = $row['campaignBudget'] ?? [];
            $out[] = [
                'id' => (string) ($campaign['id'] ?? ''),
                'name' => (string) ($campaign['name'] ?? ''),
                'status' => (string) ($campaign['status'] ?? ''),
                'channel' => (string) ($campaign['advertisingChannelType'] ?? ''),
                'bidding_strategy' => (string) ($campaign['biddingStrategyType'] ?? ''),
                'budget_micros' => (int) ($budget['amountMicros'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $results
     * @return list<array<string,mixed>>
     */
    private function mapReport(array $results): array
    {
        $out = [];
        foreach ($results as $row) {
            $metrics = $row['metrics'] ?? [];
            $campaign = $row['campaign'] ?? [];
            $segments = $row['segments'] ?? [];
            $out[] = [
                'campaign_id' => (string) ($campaign['id'] ?? ''),
                'campaign_name' => (string) ($campaign['name'] ?? ''),
                'date' => (string) ($segments['date'] ?? ''),
                'device' => (string) ($segments['device'] ?? ''),
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'cost_micros' => (int) ($metrics['costMicros'] ?? 0),
                'ctr' => (float) ($metrics['ctr'] ?? 0),
                'conversions' => (float) ($metrics['conversions'] ?? 0),
                'conversions_value' => (float) ($metrics['conversionsValue'] ?? 0),
            ];
        }

        return $out;
    }
}
