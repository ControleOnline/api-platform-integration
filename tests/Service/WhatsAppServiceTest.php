<?php

namespace ControleOnline\Tests\Service;

use ControleOnline\Service\TaskInterationService;
use ControleOnline\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class WhatsAppServiceTest extends TestCase
{
    public function testConstructorDoesNotRequireTheTechnicalWhatsAppUser(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');

        $service = new WhatsAppService(
            $manager,
            $this->createMock(TaskInterationService::class)
        );

        self::assertInstanceOf(WhatsAppService::class, $service);
    }
}
