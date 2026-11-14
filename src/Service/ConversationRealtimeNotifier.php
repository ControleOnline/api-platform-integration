<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pushes conversation events (store=websocket) to the right devices.
 *
 * - a private /ai chat goes only to the device that asked (never to PDV/displays);
 * - customer conversations (WhatsApp) go to the company's MANAGER devices, where
 *   attendants follow them in /ai.
 */
class ConversationRealtimeNotifier
{
    public function __construct(
        private EntityManagerInterface $manager,
        private IntegrationService $integrationService,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public function notify(People $company, Task $task, array $event, ?string $targetDevice = null): int
    {
        $payload = json_encode([array_merge([
            'store' => 'websocket',
            'event' => 'mcp_chat_reply',
            'company' => $company->getId(),
            'companyId' => $company->getId(),
            'task_id' => $task->getId(),
            'task_type' => $task->getType(),
            'sentAt' => date(DATE_ATOM),
        ], $event)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return 0;
        }

        $devices = $targetDevice !== null && trim($targetDevice) !== ''
            ? $this->findDevice($targetDevice)
            : $this->managerDevices($company);

        if ($devices === []) {
            return 0;
        }

        return count($this->integrationService->addDeviceIntegrations($payload, 'Websocket', $devices));
    }

    /** @return list<Device> */
    private function findDevice(string $deviceId): array
    {
        $device = $this->manager->getRepository(Device::class)->findOneBy(['device' => trim($deviceId)]);

        return $device instanceof Device ? [$device] : [];
    }

    /** @return list<Device> */
    private function managerDevices(People $company): array
    {
        $devices = [];
        foreach ($this->manager->getRepository(DeviceConfig::class)->findBy(['people' => $company]) as $config) {
            if (!$config instanceof DeviceConfig || strtoupper(trim((string) $config->getType())) !== 'MANAGER') {
                continue;
            }
            $device = $config->getDevice();
            if ($device instanceof Device) {
                $devices[(string) $device->getDevice()] = $device;
            }
        }

        return array_values($devices);
    }
}
