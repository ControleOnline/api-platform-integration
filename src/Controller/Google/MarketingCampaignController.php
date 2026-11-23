<?php

declare(strict_types=1);

namespace ControleOnline\Controller\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\Google\GoogleAdsConversionsService;
use ControleOnline\Service\Google\GoogleAdsRequestException;
use ControleOnline\Service\Google\GoogleAdsWriterService;
use ControleOnline\Service\Google\GoogleMarketingNotConnectedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

/**
 * Google Ads mutations (pause/resume/edit) + offline conversion upload.
 * Separated from report reads so authorization and validation can tighten here
 * without crowding MarketingReportController.
 */
class MarketingCampaignController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleAdsWriterService $writer,
        private readonly GoogleAdsConversionsService $conversions,
    ) {
    }

    #[Route('/marketing/ads/campaigns/{id}/pause', name: 'marketing_ads_pause', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function pause(string $id, Request $request): JsonResponse
    {
        return $this->runWriter($id, $request, fn (People $p) => $this->writer->pauseCampaign($p, $id));
    }

    #[Route('/marketing/ads/campaigns/{id}/resume', name: 'marketing_ads_resume', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function resume(string $id, Request $request): JsonResponse
    {
        return $this->runWriter($id, $request, fn (People $p) => $this->writer->resumeCampaign($p, $id));
    }

    #[Route('/marketing/ads/campaigns/{id}', name: 'marketing_ads_update', methods: ['PUT'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $payload = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        return $this->runWriter($id, $request, fn (People $p) => $this->writer->updateCampaign($p, $id, $payload));
    }

    #[Route('/marketing/ads/conversions/upload', name: 'marketing_ads_conversions_upload', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function uploadConversions(Request $request): JsonResponse
    {
        try {
            $payload = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $people = $this->resolvePeople($payload['people'] ?? $payload['provider_id'] ?? null);
        if (!$people instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        $conversions = is_array($payload['conversions'] ?? null) ? $payload['conversions'] : [];

        try {
            return new JsonResponse($this->conversions->uploadConversions($people, $conversions));
        } catch (GoogleMarketingNotConnectedException $e) {
            return new JsonResponse(['error' => 'not_connected', 'scope_set' => $e->scopeSet], Response::HTTP_UNAUTHORIZED);
        } catch (GoogleAdsRequestException $e) {
            return new JsonResponse(['error' => 'ads_failed', 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    private function runWriter(string $campaignId, Request $request, callable $operation): JsonResponse
    {
        $people = $this->resolvePeople($request->query->get('people') ?? $request->query->get('provider_id'));
        if (!$people instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($operation($people));
        } catch (GoogleMarketingNotConnectedException $e) {
            return new JsonResponse(['error' => 'not_connected', 'scope_set' => $e->scopeSet], Response::HTTP_UNAUTHORIZED);
        } catch (GoogleAdsRequestException $e) {
            return new JsonResponse(['error' => 'ads_failed', 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    private function resolvePeople(mixed $reference): ?People
    {
        if (is_string($reference) && preg_match('#/people/(\d+)$#', $reference, $m)) {
            $reference = (int) $m[1];
        }
        if (!is_numeric($reference)) {
            return null;
        }

        return $this->entityManager->getRepository(People::class)->find((int) $reference);
    }
}
