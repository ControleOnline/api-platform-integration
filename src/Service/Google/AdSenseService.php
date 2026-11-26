<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * AdSense Management API v2 reader.
 * Lists accounts/sites and generates revenue reports for the dashboard.
 */
final class AdSenseService
{
    private const BASE_URL = 'https://adsense.googleapis.com/v2';
    private const CONFIG_ACCOUNT = 'ADSENSE_ACCOUNT';
    private const CACHE_TTL = 900;

    private const DEFAULT_METRICS = ['ESTIMATED_EARNINGS', 'PAGE_VIEWS', 'IMPRESSIONS', 'CLICKS', 'IMPRESSIONS_RPM', 'PAGE_VIEWS_RPM'];
    private const DEFAULT_DIMENSIONS = ['DATE'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleMarketingClientFactory $clientFactory,
        private readonly ConfigService $configService,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAccounts(People $people): array
    {
        $body = $this->get($people, '/accounts');
        $accounts = is_array($body['accounts'] ?? null) ? $body['accounts'] : [];
        return array_values(array_map(static fn (array $a) => [
            'name' => (string) ($a['name'] ?? ''),
            'display_name' => (string) ($a['displayName'] ?? ''),
            'timezone' => (string) ($a['timeZone']['id'] ?? ''),
        ], $accounts));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSites(People $people, string $accountName): array
    {
        $body = $this->get($people, '/' . trim($accountName, '/') . '/sites');
        $sites = is_array($body['sites'] ?? null) ? $body['sites'] : [];
        return array_values(array_map(static fn (array $s) => [
            'name' => (string) ($s['name'] ?? ''),
            'domain' => (string) ($s['domain'] ?? ''),
            'state' => (string) ($s['state'] ?? ''),
        ], $sites));
    }

    /**
     * @param array{startDate?:string,endDate?:string,metrics?:list<string>,dimensions?:list<string>} $filters
     * @return array{rows:list<array<string,mixed>>,headers:array<string,string>}
     */
    public function runReport(People $people, array $filters = []): array
    {
        $accountName = $this->resolveAccountName($people);
        $metrics = $filters['metrics'] ?? self::DEFAULT_METRICS;
        $dimensions = $filters['dimensions'] ?? self::DEFAULT_DIMENSIONS;
        [$startYear, $startMonth, $startDay] = $this->splitDate($filters['startDate'] ?? date('Y-m-d', strtotime('-30 days')));
        [$endYear, $endMonth, $endDay] = $this->splitDate($filters['endDate'] ?? date('Y-m-d'));

        $cacheKey = 'adsense.report.' . $people->getId() . '.' . sha1(json_encode([$accountName, $metrics, $dimensions, $startYear, $startMonth, $startDay, $endYear, $endMonth, $endDay]));
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $item->get();
        }

        $query = http_build_query([
            'startDate.year' => $startYear, 'startDate.month' => $startMonth, 'startDate.day' => $startDay,
            'endDate.year' => $endYear, 'endDate.month' => $endMonth, 'endDate.day' => $endDay,
        ]) . '&' . implode('&', array_map(static fn (string $m) => 'metrics=' . rawurlencode($m), $metrics))
            . '&' . implode('&', array_map(static fn (string $d) => 'dimensions=' . rawurlencode($d), $dimensions));

        $body = $this->get($people, '/' . trim($accountName, '/') . '/reports:generate?' . $query);
        $result = [
            'headers' => $this->mapHeaders($body['headers'] ?? []),
            'rows' => $this->mapRows($body['rows'] ?? []),
        ];

        $item->set($result);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $result;
    }

    private function resolveAccountName(People $people): string
    {
        $raw = $this->configService->getConfig($people, self::CONFIG_ACCOUNT);
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            throw new AdSenseRequestException('ADSENSE_ACCOUNT not configured for this company.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(People $people, string $path): array
    {
        $accessToken = $this->clientFactory->accessTokenFor($people, GoogleMarketingScopes::SET_ADSENSE);
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);
            $body = json_decode($response->getContent(false), true);
        } catch (HttpClientException $e) {
            throw new AdSenseRequestException($e->getMessage(), 0, $e);
        }

        if (!is_array($body) || isset($body['error'])) {
            throw new AdSenseRequestException((string) ($body['error']['message'] ?? 'Unknown AdSense error'));
        }

        return $body;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function splitDate(string $date): array
    {
        $parts = array_map('intval', explode('-', $date));
        return [$parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0];
    }

    /**
     * @param list<array<string,mixed>> $headers
     * @return array<string,string>
     */
    private function mapHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $h) {
            $out[(string) ($h['name'] ?? '')] = (string) ($h['type'] ?? '');
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row['cells'] ?? [] as $cell) {
                $cells[] = (string) ($cell['value'] ?? '');
            }
            $out[] = $cells;
        }
        return $out;
    }
}
