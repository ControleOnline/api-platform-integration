<?php

namespace ControleOnline\Controller\WhatsApp;

use ControleOnline\Entity\People;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\PeopleRoleService;
use ControleOnline\Service\WhatsAppEmbeddedSignupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Connections screen (MANAGER): connect a WhatsApp number to the Cloud API through the
 * Meta Embedded Signup popup instead of pasting token and phone_number_id by hand.
 */
class WhatsAppEmbeddedSignupController
{
    public function __construct(
        private WhatsAppEmbeddedSignupService $signupService,
        private PeopleRoleService $peopleRoleService,
        private EntityManagerInterface $manager,
        private LoggerService $loggerService,
    ) {
    }

    #[Route('/whatsapp/embedded-signup/config', name: 'whatsapp_embedded_signup_config', methods: ['GET'])]
    #[IsGranted('ROLE_HUMAN')]
    public function config(): JsonResponse
    {
        return new JsonResponse($this->signupService->getClientConfig());
    }

    #[Route('/whatsapp/embedded-signup', name: 'whatsapp_embedded_signup', methods: ['POST'])]
    #[IsGranted('ROLE_HUMAN')]
    public function complete(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }

        $companyId = (int) preg_replace('/\D/', '', (string) ($payload['people'] ?? ''));
        $company = $companyId ? $this->manager->getRepository(People::class)->find($companyId) : null;
        if (!$company instanceof People) {
            return new JsonResponse(['error' => 'Empresa não informada.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$this->peopleRoleService->canAdministerCompany($company)) {
            return new JsonResponse(['error' => 'Sem permissão para configurar esta empresa.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $connection = $this->signupService->complete($company, $payload);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->loggerService->getLogger('WhatsAppCloud')->error('Embedded signup failed', [
                'company' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $phone = $connection->getPhone();

        return new JsonResponse([
            'id' => $connection->getId(),
            'name' => $connection->getName(),
            'channel' => $connection->getChannel(),
            'phone' => $phone ? $phone->getDdi() . $phone->getDdd() . $phone->getPhone() : null,
            'onboarding' => $connection->getChannelConfigArray()['onboarding'] ?? null,
        ], Response::HTTP_CREATED);
    }
}
