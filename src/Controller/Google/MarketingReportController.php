<?php

declare(strict_types=1);

namespace ControleOnline\Controller\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\Google\AdSenseRequestException;
use ControleOnline\Service\Google\AdSenseService;
use ControleOnline\Service\Google\GoogleAdsReaderService;
use ControleOnline\Service\Google\GoogleAdsRequestException;
use ControleOnline\Service\Google\GoogleAnalyticsRequestException;
use ControleOnline\Service\Google\GoogleAnalyticsService;
use ControleOnline\Service\Google\GoogleMarketingNotConnectedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

/**
 * Read endpoints for the marketing dashboard (ui-manager) and MCP tools.
 * Mutation lives in MarketingCampaignController.
 */
class MarketingReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleAnalyticsService $analyticsService,
        private readonly GoogleAdsReaderService $adsReader,
        private readonly AdSenseService $adsenseService,
    ) {
    }

    #[Route('/marketing/ga4/report', name: 'marketing_ga4_report', methods: ['GET'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function ga4Report(Request $request): JsonResponse
    {
        $people = $this->resolvePeople($request);
        if (!$people instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($this->analyticsService->runReport($people, $this->reportFilters($request)));
        } catch (GoogleMarketingNotConnectedException $e) {
            return new JsonResponse(['error' => 'not_connected', 'scope_set' => $e->scopeSet], Response::HTTP_UNAUTHORIZED);
        } catch (GoogleAnalyticsRequestException $e) {
            return new JsonResponse(['error' => 'ga4_failed', 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/marketing/ads/campaigns', name: 'marketing_ads_campaigns', methods: ['GET'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function adsCampaigns(Request $request): JsonResponse
    {
        $people = $this->resolvePeople($request);
        if (!$people instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['campaigns' => $this->adsReader->listCampaigns($people)]);
        } catch (GoogleMarketingNotConnectedException $e) {
            return new JsonResponse(['error' => 'not_connected', 'scope_set' => $e->scopeSet], Response::HTTP_UNAUTHORIZED);
        } catch (GoogleAdsRequestException $e) {
            return new JsonResponse(['error' => 'ads_failed', 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/marketing/ads/report', name: 'marketing_ads_report', methods: ['GET'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function adsReport(Request $request): JsonResponse
    {
        $people = $this->resolvePeople($request);
        if (!$people instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['rows' => $this->adsReader->runReport($people, $this->reportFilters($request))]);
        } catch (GoogleMarketingNotConnectedException $e) {
            return new JsonResponse(['error' => 'not_connected', 'scope_set' => $e->scopeSet], Response::HTTP_UNAUTHORIZED);
        } catch (GoogleAdsRequestException $e) {
            return new JsonResponse(['error' => 'ads_failed', 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/marketing/adsense/report', name: 'marketing_adsense_report', methods: ['GET'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function adsenseReport(Request $request): JsonResponse
    {
        $people = $this->resolvePeople($request);
        if (!$people instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($this->adsenseService->runReport($people, $this->reportFilters($request)));
        } catch (GoogleMarketingNotConnectedException $e) {
            return new JsonResponse(['error' => 'not_connected', 'scope_set' => $e->scopeSet], Response::HTTP_UNAUTHORIZED);
        } catch (AdSenseRequestException $e) {
            return new JsonResponse(['error' => 'adsense_failed', 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/marketing/adsense/accounts', name: 'marketing_adsense_accounts', methods: ['GET'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function adsenseAccounts(Request $request): JsonResponse
    {
        $people = $this->resolvePeople($request);
        if (!$people instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['accounts' => $this->adsenseService->listAccounts($people)]);
        } catch (GoogleMarketingNotConnectedException $e) {
            return new JsonResponse(['error' => 'not_connected', 'scope_set' => $e->scopeSet], Response::HTTP_UNAUTHORIZED);
        } catch (AdSenseRequestException $e) {
            return new JsonResponse(['error' => 'adsense_failed', 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    private function resolvePeople(Request $request): ?People
    {
        $reference = $request->query->get('people') ?? $request->query->get('people_id') ?? $request->query->get('provider_id');
        if (is_string($reference) && preg_match('#/people/(\d+)$#', $reference, $m)) {
            $reference = (int) $m[1];
        }
        if (!is_numeric($reference)) {
            return null;
        }

        return $this->entityManager->getRepository(People::class)->find((int) $reference);
    }

    /**
     * @return array<string,mixed>
     */
    private function reportFilters(Request $request): array
    {
        $out = [];
        foreach (['startDate', 'endDate', 'segment', 'limit'] as $key) {
            $value = $request->query->get($key);
            if ($value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }
        foreach (['metrics', 'dimensions'] as $key) {
            $all = $request->query->all();
            if (isset($all[$key])) {
                $out[$key] = is_array($all[$key]) ? array_values($all[$key]) : [(string) $all[$key]];
            }
        }
        return $out;
    }
}
