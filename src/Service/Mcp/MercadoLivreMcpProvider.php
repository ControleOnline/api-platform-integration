<?php

declare(strict_types=1);

namespace ControleOnline\\Service\\Mcp;

use ControleOnline\\Entity\\People;
use ControleOnline\\Service\\McpMercadoLivreProviderInterface;
use ControleOnline\\Service\\MercadoLivreService;
use ControleOnline\\Service\\PeopleRoleService;
use Doctrine\\ORM\\EntityManagerInterface;

/**
 * Exposes the existing Mercado Livre integration through tenant-scoped MCP tools.
 * OAuth and token persistence remain owned by MercadoLivreService.
 */
final class MercadoLivreMcpProvider implements McpMercadoLivreProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MercadoLivreService $mercadoLivreService,
        private readonly PeopleRoleService $peopleRoleService,
    ) {
    }

    public function getStatus(int $companyId): array
    {
        $company = $this->resolveAccessibleCompany($companyId);
        $state = $this->mercadoLivreService->getStoredIntegrationState($company);

        return [
            'company_id' => $companyId,
            'marketplace' => 'mercadolivre',
            'connected' => (bool) ($state['connected'] ?? false),
            'seller_id' => $state['seller_id'] ?? null,
            'imported_product_count' => (int) ($state['imported_product_count'] ?? 0),
            'last_sync_at' => $state['last_sync_at'] ?? null,
            'last_error_code' => $state['last_error_code'] ?? null,
        ];
    }

    public function getCurrentUser(int $companyId): array
    {
        $company = $this->resolveConnectedCompany($companyId);
        return $this->mercadoLivreService->mcpGetCurrentUser($company);
    }

    public function searchItems(int $companyId, int $offset = 0, int $limit = 50): array
    {
        $company = $this->resolveConnectedCompany($companyId);
        return $this->mercadoLivreService->mcpSearchItems($company, $offset, $limit);
    }

    public function getItem(int $companyId, string $itemId): array
    {
        return $this->mercadoLivreService->mcpGetItem($this->resolveConnectedCompany($companyId), $itemId);
    }

    public function getItemDescription(int $companyId, string $itemId): array
    {
        return $this->mercadoLivreService->mcpGetItemDescription($this->resolveConnectedCompany($companyId), $itemId);
    }

    public function getCategory(int $companyId, string $categoryId): array
    {
        return $this->mercadoLivreService->mcpGetCategory($this->resolveConnectedCompany($companyId), $categoryId);
    }

    public function getOrder(int $companyId, string $orderId): array
    {
        return $this->mercadoLivreService->mcpGetOrder($this->resolveConnectedCompany($companyId), $orderId);
    }

    public function importProducts(int $companyId, int $showcaseId, int $limit = 50): array
    {
        return $this->mercadoLivreService->importProducts(
            $this->resolveConnectedCompany($companyId),
            $limit,
            $showcaseId
        );
    }

    private function resolveConnectedCompany(int $companyId): People
    {
        $company = $this->resolveAccessibleCompany($companyId);
        $state = $this->mercadoLivreService->getStoredIntegrationState($company);
        if (empty($state['connected'])) {
            throw new \InvalidArgumentException('Mercado Livre is not connected for this company');
        }
        return $company;
    }

    private function resolveAccessibleCompany(int $companyId): People
    {
        $company = $this->entityManager->getRepository(People::class)->find($companyId);
        if (!$company instanceof People) {
            throw new \InvalidArgumentException('Company not found');
        }

        foreach ($this->peopleRoleService->getAccessibleCompaniesForPeople() as $accessibleCompany) {
            if ($accessibleCompany instanceof People && (int) $accessibleCompany->getId() === $companyId) {
                return $company;
            }
        }

        throw new \InvalidArgumentException('Company is not accessible to the current user');
    }
}
