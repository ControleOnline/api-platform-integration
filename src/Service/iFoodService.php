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
use ControleOnline\Entity\User;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use Exception;

class iFoodService
{
    private static $extraFields;
    private static $iFoodPeople;
    protected static $logger;


    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerService $loggerService,
        private HttpClientInterface $httpClient,
        private ExtraDataService $extraDataService,
        private PeopleService $peopleService,
        private OrderService $orderService,
        private StatusService $statusService,
        private AddressService $addressService,
        private ProductService $productService,
        private WebsocketClient $websocketClient,
        private ConfigService $configService,
        private DeviceService $deviceService,
        private OrderPrintService $orderPrintService,
        private InvoiceService $invoiceService,
        private WalletService $walletService,
        private OrderProductService $orderProductService
    ) {
        self::$logger = $loggerService->getLogger('iFood');
        self::$extraFields = $this->extraDataService->discoveryExtraFields('Code', 'iFood', '{}', 'code');
        self::$iFoodPeople = $this->peopleService->discoveryPeople('14380200000121', null, null, 'Ifood.com Agência de Restaurantes Online S.A', 'J');
    }

    public  function integrate(Integration $integration)
    {

        $json = json_decode($integration->getBody(), true);

        $fullCode = $json['fullCode'];
        $this->addLog('info', 'Código recebido', ['code' =>  $json['fullCode']]);

        switch ($fullCode) {
            case 'PLACED':
                return $this->addOrder($json);
                break;
            case 'CANCELLED':
            case 'CANCELLATION_REQUESTED':
                return $this->cancelOrder($json);
                break;
            default:
                return null;
                break;
        }
    }

    private function cancelOrder(array $json): ?Order
    {
        $orderId =  $json['orderId'] ?? null;

        $order = $this->extraDataService->getEntityByExtraData(self::$extraFields, $orderId, Order::class);
        if ($order) {
            $status = $this->statusService->discoveryStatus('canceled', 'canceled', 'order');

            $other = (array) $order->getOtherInformations(true);
            $other[$json['fullCode']] = $json;
            $order->addOtherInformations('iFood', $other);
            $order->setStatus($status);
            $this->entityManager->persist($order);
            $this->entityManager->flush();
            //@todo cancelar faturas
            return $order;
        }
        return null;
    }

    private function getApiUser(): User
    {
        return $this->entityManager->getRepository(User::class)->find(7);
    }

    private function addOrder(array $json): ?Order
    {

        $orderId =  $json['orderId'] ?? null;
        $merchantId = $json['merchantId'] ?? null;

        if (!$orderId || !$merchantId) {
            $this->addLog('error', 'Dados do pedido incompletos', ['json' =>  $json]);
            return null;
        }

        $provider = $this->extraDataService->getEntityByExtraData(self::$extraFields, $merchantId, People::class);
        $order = $this->extraDataService->getEntityByExtraData(self::$extraFields, $orderId, Order::class);
        if ($order)
            return $order;

        $orderDetails = $this->fetchOrderDetails($orderId);
        if (!$orderDetails) {
            $this->addLog('error', 'Não foi possível obter detalhes do pedido', ['orderId' => $orderId]);
            return null;
        }

        $json['order']  = $orderDetails;
        $status = $this->statusService->discoveryStatus('pending', 'quote', 'order');
        $client = $this->discoveryClient($provider, $orderDetails['customer'] ?? []);

        $order = new Order();
        $order->setClient($client);
        $order->setProvider($provider);
        $order->setPayer($client);
        $order->setStatus($status);
        $order->setAlterDate(new DateTime());
        $order->setApp('iFood');
        $order->setOrderType('sale');
        $order->addOtherInformations('iFood', [$json['fullCode'] => $json]);
        $order->setUser($this->getApiUser());
        $totalPrice = $orderDetails['total']['orderAmount'] ?? 0;
        $order->setPrice($totalPrice);

        $this->entityManager->persist($order);

        $this->addProducts($order, $orderDetails['items']);
        $this->addDelivery($order, $orderDetails);
        $this->addPayments($order, $orderDetails);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->addLog('info', 'Pedido processado com sucesso', ['orderId' => $orderId]);

        $this->printOrder($order);
        return $this->discoveryiFoodCode($order, $orderId);
    }


    private function printOrder(Order $order)
    {
        $devices = $this->configService->getConfig($order->getProvider(), 'ifood-devices', true);

        if ($devices)
            $devices = $this->deviceService->findDevices($devices);

        foreach ($devices as $device)
            $this->orderPrintService->generatePrintData($order, $device);
    }

    private function addReceiveInvoices(Order $order, array $payments)
    {
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), 'iFood');
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        foreach ($payments as $payment)
            $this->invoiceService->createInvoiceByOrder($order, $payment['value'], $payment['prepaid'] ? $status : null, new DateTime(), null,  $iFoodWallet);
    }

    private function addDelivery(Order &$order, array $orderDetails)
    {
        $delivery = $orderDetails['delivery'];
        $deliveryAddress = $delivery['deliveryAddress'];
        if ($delivery['deliveredBy'] != 'MERCHANT')
            $this->addDeliveryFee($order, $orderDetails['total']);

        $deliveryAddress = $this->addressService->discoveryAddress(
            $order->getClient(),
            (int) $deliveryAddress['postalCode'],
            (int) $deliveryAddress['streetNumber'],
            $deliveryAddress['streetName'],
            $deliveryAddress['neighborhood'],
            $deliveryAddress['city'],
            $deliveryAddress['state'],
            $deliveryAddress['country'],
            $deliveryAddress['complement'],
            (int) $deliveryAddress['coordinates']['latitude'],
            (int) $deliveryAddress['coordinates']['longitude'],
        );

        $order->setAddressDestination($deliveryAddress);
    }

    private function addDeliveryFee(Order &$order, array $payments)
    {
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), 'iFood');
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        $order->setRetrieveContact(self::$iFoodPeople);

        $this->invoiceService->createInvoice(
            $order,
            $order->getProvider(),
            self::$iFoodPeople,
            $payments['deliveryFee'],
            $status,
            new DateTime(),
            $iFoodWallet,
            $iFoodWallet
        );
    }

    private function addFees(Order $order, array $payments)
    {
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), 'iFood');
        $this->invoiceService->createInvoice(
            $order,
            $order->getProvider(),
            self::$iFoodPeople,
            $payments['additionalFees'],
            $status,
            new DateTime(),
            $iFoodWallet,
            $iFoodWallet
        );
    }

    private function addPayments(Order $order, array $orderDetails)
    {

        var_dump($orderDetails);
        $this->addReceiveInvoices($order, $orderDetails['payments']['methods']);
        $this->addFees($order, $orderDetails['total']);
    }
    private function addProducts(Order $order, array $items, ?Product $parentProduct = null, ?OrderProduct $orderProductParent = null, string $productType = 'product')
    {
        foreach ($items as $item) {

            if ((isset($item['options']) && $item['options']) || (isset($item['customizations']) && $item['customizations']))
                $productType = 'custom';

            $product = $this->discoveryProduct($order, $item, $parentProduct, $productType);
            $productGroup = null;
            if (isset($item['groupName']))
                $productGroup = $this->discoveryProductGroup($parentProduct ?: $product, $item['groupName']);
            $orderProduct =  $this->orderProductService->addOrderProduct($order, $product, $item['quantity'], $item['unitPrice'], $productGroup, $parentProduct, $orderProductParent);
            if (isset($item['options']) && $item['options'])
                $this->addProducts($order, $item['options'], $product, $orderProduct, 'component');
            if (isset($item['customizations']) && $item['customizations'])
                $this->addProducts($order, $item['customizations'], $product, $orderProduct, 'component');
        }
    }

    private function getAccessToken(): ?string
    {
        try {
            $response = $this->httpClient->request('POST', 'https://merchant-api.ifood.com.br/authentication/v1.0/oauth/token', [
                'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'grantType' => 'client_credentials',
                    'clientId' => $_ENV['OAUTH_IFOOD_CLIENT_ID'],
                    'clientSecret' => $_ENV['OAUTH_IFOOD_CLIENT_SECRET'],
                ]),
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            if ($statusCode !== 200) {
                $this->addLog('error', 'Erro ao obter token de acesso do iFood');
                $this->addLog('error', 'Status: ' . $statusCode);
                $this->addLog('error', 'Response: ' . $responseBody);
                return null;
            }

            $data = $response->toArray();
            if (!isset($data['accessToken'])) {
                $this->addLog('error', 'Token de acesso não encontrado na resposta', [
                    'response' => $responseBody
                ]);
                return null;
            }

            return $data['accessToken'];
        } catch (Exception $e) {
            $this->addLog('error', 'Erro ao buscar token de acesso', ['error' => $e->getMessage()]);
            return null;
        }
    }


    private function fetchOrderDetails(string $orderId): ?array
    {
        try {

            $token = $this->getAccessToken();
            if (!$token) {
                $this->addLog('error', 'Token de acesso não obtido');
                return null;
            }

            $response = $this->httpClient->request('GET', 'https://merchant-api.ifood.com.br/order/v1.0/orders/' . $orderId, [
                'headers' => [
                    'Authorization' => 'Bearer ' .   $token,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->addLog('error', 'Erro na API do iFood', ['status' => $response->getStatusCode()]);
                return null;
            }

            return $response->toArray();
        } catch (\Exception $e) {
            $this->addLog('error', 'Erro ao buscar detalhes do pedido', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function discoveryClient(People $provider, array $customerData): ?People
    {
        if (empty($customerData['name']) || empty($customerData['phone'])) {
            self::$logger->warning('Dados do cliente incompletos', ['customer' => $customerData]);
            return null;
        }

        $codClienteiFood = $customerData['id'];

        $client = $this->extraDataService->getEntityByExtraData(self::$extraFields, $codClienteiFood, People::class);

        $phone = [
            'ddd' => '11',
            'phone' => $customerData['phone']['number']
        ];

        $document = $customerData['documentNumber'];

        if (!$client)
            $client = $this->peopleService->discoveryPeople($document, null, $phone, $customerData["name"]);

        $this->peopleService->discoveryClient($provider, $client);

        return $this->discoveryiFoodCode($client, $codClienteiFood);
    }

    private function discoveryiFoodCode(object $entity, string $code)
    {
        return $this->extraDataService->discoveryExtraData($entity->getId(), self::$extraFields, $code,  $entity);
    }

    private function discoveryProductGroup(Product $parentProduct, string $groupName): ProductGroup
    {
        $productGroup = $this->entityManager->getRepository(ProductGroup::class)->findOneBy([
            'productGroup' => $groupName,
            'productParent' => $parentProduct
        ]);

        if (!$productGroup) {
            $productGroup = new ProductGroup();
            $productGroup->setProductParent($parentProduct);
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

    private function discoveryProduct(Order $order, array $item, ?Product $parentProduct = null, string $productType = 'product'): Product
    {
        $codProductiFood = $item['id'];
        $product = $this->extraDataService->getEntityByExtraData(self::$extraFields, $codProductiFood, Product::class);

        if (!$product && !empty($item['externalCode']))
            $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                'company' => $order->getProvider(),
                'id' => $item['externalCode']
            ]);

        if (!$product && !empty($item['ean']))
            $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                'company' => $order->getProvider(),
                'sku' => $item['ean']
            ]);

        if (!$product)
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['company' => $order->getProvider(), 'product' => $item['name']]);

        if (!$product) {
            $productUnity = $this->entityManager->getRepository(ProductUnity::class)->findOneBy(['productUnit' => 'UN']);

            $product = new Product();
            $product->setProduct($item['name']);
            $product->setSku(empty($item['ean']) ? null : $item['ean']);
            $product->setPrice($item['unitPrice']);
            $product->setProductUnit($productUnity);
            $product->setType($productType);
            $product->setProductCondition('new');
            $product->setCompany($order->getProvider());

            $this->entityManager->persist($product);
            $this->entityManager->flush();
            if ($parentProduct && isset($item['groupName'])) {
                $productGroup = $this->discoveryProductGroup($parentProduct, $item['groupName']);
                $productGroupProduct = new ProductGroupProduct();
                $productGroupProduct->setProduct($parentProduct);
                $productGroupProduct->setProductChild($product);
                $productGroupProduct->setProductType($productType);
                $productGroupProduct->setProductGroup($productGroup);
                $productGroupProduct->setQuantity($item['quantity']);
                $productGroupProduct->setPrice($item['unitPrice']);
                $this->entityManager->persist($productGroupProduct);
                $this->entityManager->flush();
            }
        }


        return $this->discoveryiFoodCode($product, $codProductiFood);
    }


    private function addLog(string $type, $log)
    {
        echo $log;
        self::$logger->$type($log);
    }
}
