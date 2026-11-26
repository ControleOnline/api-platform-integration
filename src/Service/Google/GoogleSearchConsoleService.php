<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Search Console (webmasters/v3) reader.
 * Lists verified sites and runs searchAnalytics queries for the dashboard and
 * the MCP tool. Organic counterpart to the paid metrics of #84.
 */
final class GoogleSearchConsoleService
{
    private const BASE_URL = 'https://searchconsole.googleapis.com/webmasters/v3';
    private const CONFIG_SITE = 'SEARCH_CONSOLE_SITE';
    private const CACHE_TTL = 600;
    private const DEFAULT_DIMENSIONS = ['query', 'page'];
    private const DEFAULT_ROW_LIMIT = 500;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleMarketingClientFactory $clientFactory,
        private readonly ConfigService $configService,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return list<array{siteUrl:string,permissionLevel:string}>
     */
    public function listSites(People $people): array
    {
        $body = $this->get($people, '/sites');
        $sites = is_array($body['siteEntry'] ?? null) ? $body['siteEntry'] : [];
        return array_values(array_map(static fn (array $s) => [
            'siteUrl' => (string) ($s['siteUrl'] ?? ''),
            'permissionLevel' => (string) ($s['permissionLevel'] ?? ''),
        ], $sites));
    }

    /**
     * @param array{startDate?:string,endDate?:string,dimensions?:list<string>,row_limit?:int,start_row?:int} $filters
     * @return array{site_url:string,rows:list<array<string,mixed>>}
     */
    public function runReport(People $people, array $filters = []): array
    {
        $siteUrl = $this->resolveSiteUrl($people);
        $dimensions = $filters['dimensions'] ?? self::DEFAULT_DIMENSIONS;
        $startDate = $filters['startDate'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $filters['endDate'] ?? date('Y-m-d');
        $rowLimit = isset($filters['row_limit']) ? max(1, min(25000, (int) $filters['row_limit'])) : self::DEFAULT_ROW_LIMIT;
        $startRow = isset($filters['start_row']) ? max(0, (int) $filters['start_row']) : 0;

        $cacheKey = 'gsc.' . $people->getId() . '.' . sha1(json_encode([$siteUrl, $dimensions, $startDate, $endDate, $rowLimit, $startRow]));
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $item->get();
        }

        $accessToken = $this->clientFactory->accessTokenFor($people, GoogleMarketingScopes::SET_WEBMASTERS);
        $url = self::BASE_URL . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => $dimensions,
                    'rowLimit' => $rowLimit,
                    'startRow' => $startRow,
                ], JSON_THROW_ON_ERROR),
            ]);
            $body = json_decode($response->getContent(false), true);
        } catch (HttpClientException $e) {
            throw new GoogleSearchConsoleRequestException($e->getMessage(), 0, $e);
        }

        if (!is_array($body) || isset($body['error'])) {
            throw new GoogleSearchConsoleRequestException((string) ($body['error']['message'] ?? 'Unknown Search Console error'));
        }

        $result = [
            'site_url' => $siteUrl,
            'rows' => $this->mapRows($body['rows'] ?? [], $dimensions),
        ];

        $item->set($result);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $result;
    }

    private function resolveSiteUrl(People $people): string
    {
        $raw = $this->configService->getConfig($people, self::CONFIG_SITE);
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            throw new GoogleSearchConsoleRequestException('SEARCH_CONSOLE_SITE not configured for this company.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(People $people, string $path): array
    {
        $accessToken = $this->clientFactory->accessTokenFor($people, GoogleMarketingScopes::SET_WEBMASTERS);
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);
            $body = json_decode($response->getContent(false), true);
        } catch (HttpClientException $e) {
            throw new GoogleSearchConsoleRequestException($e->getMessage(), 0, $e);
        }

        if (!is_array($body) || isset($body['error'])) {
            throw new GoogleSearchConsoleRequestException((string) ($body['error']['message'] ?? 'Unknown Search Console error'));
        }

        return $body;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $dimensions
     * @return list<array<string,mixed>>
     */
    private function mapRows(array $rows, array $dimensions): array
    {
        $out = [];
        foreach ($rows as $row) {
            $keys = is_array($row['keys'] ?? null) ? array_values($row['keys']) : [];
            $mapped = [];
            foreach ($keys as $i => $value) {
                $mapped[$dimensions[$i] ?? ('dim_' . $i)] = (string) $value;
            }
            $mapped['clicks'] = (int) ($row['clicks'] ?? 0);
            $mapped['impressions'] = (int) ($row['impressions'] ?? 0);
            $mapped['ctr'] = (float) ($row['ctr'] ?? 0);
            $mapped['position'] = (float) ($row['position'] ?? 0);
            $out[] = $mapped;
        }

        return $out;
    }
}
