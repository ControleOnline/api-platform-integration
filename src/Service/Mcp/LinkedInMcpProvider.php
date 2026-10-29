<?php

declare(strict_types=1);

namespace ControleOnline\Service\Mcp;

use ControleOnline\Entity\People;
use ControleOnline\Service\LinkedInService;
use ControleOnline\Service\McpLinkedInProviderInterface;
use ControleOnline\Service\PeopleRoleService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bridges LinkedInService into ControleOnline MCP tools.
 */
final class LinkedInMcpProvider implements McpLinkedInProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LinkedInService $linkedInService,
        private readonly PeopleRoleService $peopleRoleService,
    ) {
    }

    public function getStatus(int $companyId): array
    {
        $provider = $this->resolveAccessibleCompany($companyId);
        $status = $this->linkedInService->getConnectionStatus($provider);

        return array_merge(['company_id' => $companyId], $status);
    }

    public function createPost(int $companyId, string $text): array
    {
        $provider = $this->resolveAccessibleCompany($companyId);
        $result = $this->linkedInService->createMemberPost($provider, $text);

        return array_merge(['company_id' => $companyId], $result);
    }

    public function getProfile(int $companyId): array
    {
        $provider = $this->resolveAccessibleCompany($companyId);
        $result = $this->linkedInService->getProfile($provider);

        return array_merge(['company_id' => $companyId], $result);
    }

    public function listOrganizations(int $companyId): array
    {
        $provider = $this->resolveAccessibleCompany($companyId);
        $result = $this->linkedInService->listOrganizations($provider);

        return array_merge(['company_id' => $companyId], $result);
    }

    public function createOrganizationPost(int $companyId, string $organizationUrn, string $text): array
    {
        $provider = $this->resolveAccessibleCompany($companyId);
        $result = $this->linkedInService->createOrganizationPost($provider, $organizationUrn, $text);

        return array_merge(['company_id' => $companyId], $result);
    }

    public function getCapabilities(int $companyId): array
    {
        $provider = $this->resolveAccessibleCompany($companyId);
        $result = $this->linkedInService->getCapabilities($provider);

        return array_merge(['company_id' => $companyId], $result);
    }

    private function resolveAccessibleCompany(int $companyId): People
    {
        $provider = $this->entityManager->getRepository(People::class)->find($companyId);
        if (!$provider instanceof People) {
            throw new \InvalidArgumentException('Company not found');
        }

        $allowed = false;
        foreach ($this->peopleRoleService->getAccessibleCompaniesForPeople() as $company) {
            if ($company instanceof People && (int) $company->getId() === $companyId) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \InvalidArgumentException('Company is not accessible to the current user');
        }

        return $provider;
    }
}
