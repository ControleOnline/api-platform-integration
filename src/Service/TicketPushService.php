<?php

namespace ControleOnline\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends a request push to every device a person is signed in on. The app stores the signed-in
 * person next to the FCM token: metadata.pushTokens.<app>.android.{deviceToken, peopleId}.
 */
class TicketPushService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private FirebaseCloudMessagingService $fcm,
        private ?LoggerInterface $logger = null,
    ) {}

    /** @param list<int> $peopleIds */
    public function send(array $peopleIds, string $title, string $body, int $taskId): int
    {
        $sent = 0;
        foreach ($this->tokensFor($peopleIds) as $token) {
            try {
                $this->fcm->sendNotificationToToken($token, $title, $body, [
                    'route' => 'TicketConversation',
                    'taskId' => (string) $taskId,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger?->warning('Unable to send ticket push.', ['taskId' => $taskId, 'tokenHash' => hash('sha256', $token), 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * @param list<int> $peopleIds
     * @return list<string>
     */
    public function tokensFor(array $peopleIds): array
    {
        $peopleIds = array_values(array_unique(array_filter(array_map('intval', $peopleIds))));
        if ($peopleIds === []) {
            return [];
        }
        $rows = $this->manager->getConnection()->fetchFirstColumn(
            "SELECT metadata FROM device WHERE metadata IS NOT NULL AND metadata LIKE '%peopleId%'"
        );
        $tokens = [];
        foreach ($rows as $raw) {
            $metadata = json_decode((string) $raw, true);
            foreach ((array) ($metadata['pushTokens'] ?? []) as $app) {
                $android = is_array($app) ? ($app['android'] ?? null) : null;
                $token = trim((string) ($android['deviceToken'] ?? ''));
                if ($token !== '' && in_array((int) ($android['peopleId'] ?? 0), $peopleIds, true)) {
                    $tokens[$token] = $token;
                }
            }
        }

        return array_values($tokens);
    }
}
