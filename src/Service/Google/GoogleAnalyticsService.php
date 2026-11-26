<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GA4 Data API reader scoped to a People's connected property.
 * Returns rows normalized as { dimensions: [...], metrics: [...] } so the
 * dashboard and the MCP tool can consume the same shape.
 */
final class GoogleAnalyticsService
{
    private const BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';
    private const CONFIG_PROPERTY_ID = 'GA4_PROPERTY_ID';
    private const CACHE_TTL = 300;

    private const DEFAULT_METRICS = ['sessions', 'activeUsers', 'screenPageViews', 'engagementRate', 'conversions', 'eventCount'];
    private const DEFAULT_DIMENSIONS = ['date', 'sessionSource', 'sessionMedium', 'sessionCampaignName'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleMarketingClientFactory $clientFactory,
        private readonly ConfigService $configService,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array{startDate?:string,endDate?:string,metrics?:list<string>,dimensions?:list<string>,limit?:int} $filters
     * @return array{rows:list<array<string,mixed>>,totals:array<string,float>,property_id:string}
     *
     * @throws GoogleMarketingNotConnectedException
     * @throws GoogleAnalyticsRequestException
     */
    public function runReport(People $people, array $filters = []): array
    {
        $propertyId = $this->resolvePropertyId($people);
        $metrics = $filters['metrics'] ?? self::DEFAULT_METRICS;
        $dimensions = $filters['dimensions'] ?? self::DEFAULT_DIMENSIONS;
        $startDate = $filters['startDate'] ?? '30daysAgo';
        $endDate = $filters['endDate'] ?? 'today';
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 500;

        $cacheKey = 'ga4.' . $people->getId() . '.' . sha1(json_encode([$propertyId, $metrics, $dimensions, $startDate, $endDate, $limit]));
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $item->get();
        }

        $accessToken = $this->clientFactory->accessTokenFor($people, GoogleMarketingScopes::SET_ANALYTICS);
        $url = self::BASE_URL . '/properties/' . rawurlencode($propertyId) . ':runReport';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
                    'dimensions' => array_map(fn (string $d) => ['name' => $d], $dimensions),
                    'metrics' => array_map(fn (string $m) => ['name' => $m], $metrics),
                    'limit' => $limit,
                ], JSON_THROW_ON_ERROR),
            ]);
            $body = json_decode($response->getContent(false), true);
        } catch (HttpClientException $e) {
            throw new GoogleAnalyticsRequestException($e->getMessage(), 0, $e);
        }

        if (!is_array($body) || isset($body['error'])) {
            throw new GoogleAnalyticsRequestException((string) ($body['error']['message'] ?? 'Unknown GA4 error'));
        }

        $result = [
            'property_id' => $propertyId,
            'rows' => $this->mapRows($body['rows'] ?? [], $dimensions, $metrics),
            'totals' => $this->mapTotals($body['totals'] ?? [], $metrics),
        ];

        $item->set($result);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $result;
    }

    private function resolvePropertyId(People $people): string
    {
        $raw = $this->configService->getConfig($people, self::CONFIG_PROPERTY_ID);
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            throw new GoogleAnalyticsRequestException('GA4_PROPERTY_ID not configured for this company.');
        }

        return $value;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $dimensions
     * @param list<string> $metrics
     * @return list<array<string,mixed>>
     */
    private function mapRows(array $rows, array $dimensions, array $metrics): array
    {
        $out = [];
        foreach ($rows as $row) {
            $dims = [];
            foreach ($row['dimensionValues'] ?? [] as $i => $value) {
                $dims[$dimensions[$i] ?? ('dim_' . $i)] = (string) ($value['value'] ?? '');
            }
            $met = [];
            foreach ($row['metricValues'] ?? [] as $i => $value) {
                $met[$metrics[$i] ?? ('met_' . $i)] = (float) ($value['value'] ?? 0);
            }
            $out[] = ['dimensions' => $dims, 'metrics' => $met];
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $totals
     * @param list<string> $metrics
     * @return array<string,float>
     */
    private function mapTotals(array $totals, array $metrics): array
    {
        $out = [];
        foreach ($totals as $row) {
            foreach ($row['metricValues'] ?? [] as $i => $value) {
                $out[$metrics[$i] ?? ('met_' . $i)] = (float) ($value['value'] ?? 0);
            }
        }

        return $out;
    }
}
