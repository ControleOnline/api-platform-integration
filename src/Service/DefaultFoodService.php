<?php

namespace ControleOnline\Service;

use ControleOnline\Service\AddressService;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\User;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use Exception;


class DefaultFoodService
{

    protected static $foodPeople;
    protected static $logger;
    protected static $app;


    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected LoggerService $loggerService,
        protected HttpClientInterface $httpClient,
        protected ExtraDataService $extraDataService,
        protected PeopleService $peopleService,
        protected OrderService $orderService,
        protected StatusService $statusService,
        protected AddressService $addressService,
        protected ProductService $productService,
        protected WebsocketClient $websocketClient,
        protected ConfigService $configService,
        protected DeviceService $deviceService,
        protected OrderPrintService $orderPrintService,
        protected InvoiceService $invoiceService,
        protected WalletService $walletService,
        protected OrderProductService $orderProductService,
        protected ProductGroupService $productGroupService
    ) {}


    protected function printOrder(Order $order)
    {
        $devices = $this->configService->getConfig($order->getProvider(), $order->getApp() . '-devices', true);

        if ($devices)
            $devices = $this->deviceService->findDevices($devices);

        foreach ($devices as $device)
            $this->orderPrintService->generatePrintData($order, $device, ['sound' => $order->getApp()]);
    }


    protected function discoveryFoodCode(object $entity, string $code)
    {
        return $this->extraDataService->discoveryExtraData($entity->getId(), self::$app, $code,  $entity);
    }

    protected function discoveryFoodCodeByEntity(object $entity)
    {
        return $this->extraDataService->getByExtraFieldByEntity(self::$app, $entity)?->getValue();
    }


    public function changeStatus(Order $order)
    {
        $this->init();
        $orderId = $this->discoveryFoodCodeByEntity($order);

        if (!$orderId) {
            return null;
        }

        $realStatus = $order->getStatus()->getRealStatus();


        match ($realStatus) {
            'cancelled' => $this->cancelByShop($orderId),
            'ready'     => $this->readyOrder($orderId),
            'delivered' => $this->deliveredOrder($orderId),
            default     => null,
        };

        return null;
    }


    protected function createOrder(
        People $client,
        People $provider,
        float $price,
        Status $status,
        array $otherInformations
    ): Order {
        $order = new Order();
        $order->setClient($client);
        $order->setProvider($provider);
        $order->setPayer($client);
        $order->setStatus($status);
        $order->setApp(self::$app);
        $order->setOrderType('sale');
        $order->addOtherInformations(self::$app, $otherInformations);

        $order->setPrice($price);

        $this->entityManager->persist($order);
        return $order;
    }

    protected function addLog(string $type, $log)
    {
        echo $log;
        self::$logger->$type($log);
    }
}
