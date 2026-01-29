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

    protected static $extraFields;
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
        protected OrderProductService $orderProductService
    ) {}


    protected function printOrder(Order $order, $sound = false)
    {
        $devices = $this->configService->getConfig($order->getProvider(), 'ifood-devices', true);

        if ($devices)
            $devices = $this->deviceService->findDevices($devices);

        foreach ($devices as $device)
            $this->orderPrintService->generatePrintData($order, $device, ['sound' => $sound]);
    }


    protected function discoveryFoodCode(object $entity, string $code)
    {
        return $this->extraDataService->discoveryExtraData($entity->getId(), self::$extraFields, $code,  $entity);
    }


    protected function createOrder(
        People $client,
        People $provider,
        float $price,
        Status $status,
        User $user,
        array $otherInformations
    ): Order {
        $order = new Order();
        $order->setClient($client);
        $order->setProvider($provider);
        $order->setPayer($client);
        $order->setStatus($status);
        $order->setAlterDate(new DateTime());
        $order->setApp(self::$app);
        $order->setOrderType('sale');
        $order->addOtherInformations(self::$app, $otherInformations);
        $order->setUser($user);

        $order->setPrice($price);

        $this->entityManager->persist($order);
        return $order;
    }


        protected function getApiUser(): User
    {
        return $this->entityManager->getRepository(User::class)->find(7);
    }

    protected function addLog(string $type, $log)
    {
        echo $log;
        self::$logger->$type($log);
    }


     protected function discoveryProductGroup(Product $parentProduct, string $groupName): ProductGroup
    {
        $productGroup = $this->entityManager->getRepository(ProductGroup::class)->findOneBy([
            'productGroup' => $groupName,
            'parentProduct' => $parentProduct
        ]);

        if (!$productGroup) {
            $productGroup = new ProductGroup();
            $productGroup->setParentProduct($parentProduct);
            $productGroup->setProductGroup($groupName);
            $productGroup->setPriceCalculation('sum');
            $productGroup->setRequired(false);
            $productGroup->setMinimum(0);
            $productGroup->setMaximum(0);
            $productGroup->setActive(true);
            $productGroup->setGroupOrder(0);
            $this->entityManager->persist($productGroup);
            $this->entityManager->flush();
        }

        return $productGroup;
    }
}
