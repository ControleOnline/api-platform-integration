<?php

namespace ControleOnline\Service;

use App\Service\AddressService;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use Exception;

class iFoodService
{
    private static $extraFields;
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

    ) {
        self::$logger = $loggerService->getLogger('iFood');
        self::$extraFields = $this->extraDataService->discoveryExtraFields('Code', 'iFood', '{}', 'code');
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

            $order->setStatus($status);
            $order->addOtherInformations('iFood', [$json['fullCode'] => $json]);
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

        // Buscar detalhes do pedido via API
        $orderDetails = $this->fetchOrderDetails($orderId);
        if (!$orderDetails) {
            $this->addLog('error', 'Não foi possível obter detalhes do pedido', ['orderId' => $orderId]);
            return null;
        }

        $status = $this->statusService->discoveryStatus('pending', 'quote', 'order');
        $client = $this->discoveryClient($provider, $orderDetails['customer'] ?? []);
        $deliveryAddress = $this->discoveryAddress($client, $orderDetails['delivery'] ?? []);


        $order = new Order();
        $order->setClient($client);
        $order->setProvider($provider);
        $order->setStatus($status);
        $order->setAlterDate(new DateTime());
        $order->setApp('iFood');
        $order->setOrderType('sale');
        $order->setAddressDestination($deliveryAddress);
        $order->addOtherInformations('iFood', [$json['fullCode'] => $json]);
        $order->setUser($this->getApiUser());
        $totalPrice = $orderDetails['total']['orderAmount'] ?? 0;
        $order->setPrice($totalPrice);

        //$this->addProducts($order, $orderDetails['items']);
        //$this->addPayments($order, $orderDetails['payments'], $orderDetails['total']);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->addLog('info', 'Pedido processado com sucesso', ['orderId' => $orderId]);

        return $this->discoveryiFoodCode($order, $orderId);
    }

    private function addPayments(Order $order, array $payments, array $total)
    {
        // @todo Armazenar informações adicionais (ex.: método de pagamento, taxa de entrega)
        $order->setOtherInformations([
            'payment' => $orderDetails['payments'] ?? [],
            'deliveryFee' => $orderDetails['total']['deliveryFee'] ?? 0,
            'subTotal' => $orderDetails['total']['subTotal'] ?? 0,
        ]);
    }

    private function addProducts(Order $order, array $items)
    {
        foreach ($items as $item) {
            $product = $this->discoveryProduct($item);
            $orderProduct = new OrderProduct();
            $orderProduct->setOrder($order);
            $orderProduct->setProduct($product);
            $orderProduct->setQuantity($item['quantity'] ?? 1);
            $orderProduct->setPrice($item['unitPrice'] ?? 0.0);
            $orderProduct->setTotal($item['totalPrice'] ?? 0.0);
            $this->entityManager->persist($orderProduct);

            if (isset($item['options'])) {
                foreach ($item['options'] as $option) {
                    $optionProduct = $this->discoveryProduct($option);
                    $additionalProduct = new OrderProduct();
                    $additionalProduct->setOrder($order);
                    $additionalProduct->setProduct($optionProduct);
                    $additionalProduct->setQuantity($option['quantity'] ?? 1);
                    $additionalProduct->setPrice($option['unitPrice'] ?? 0.0);
                    $additionalProduct->setTotal($option['totalPrice'] ?? 0.0);
                    $additionalProduct->setOrderProduct($orderProduct); // Relacionar como componente
                    $this->entityManager->persist($additionalProduct);
                }
            }
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

    private function discoveryAddress(People $client, array $deliveryData): ?Address
    {
        if (empty($deliveryData['address']['streetName']) || empty($deliveryData['address']['city'])) {
            self::$logger->warning('Dados de endereço incompletos', ['delivery' => $deliveryData]);
            return null;
        }

        $address = new Address();
        // @todo Criar Endereço

        return $address;
    }

    private function discoveryProduct(array $itemData): Product
    {
        $codProductiFood = '';
        $product = $this->extraDataService->getEntityByExtraData(self::$extraFields, $codProductiFood, Product::class);


        if (!$product)
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['product' => $itemData['name']]);

        if (!$product) {

            $this->productService->addProduct($itemData['name']);
            $product = new Product();
            // @todo Criar Produto
        }


        return $this->discoveryiFoodCode($product, $codProductiFood);
    }


    private function addLog(string $type, $log)
    {
        echo $log;
        self::$logger->$type($log);
    }
}
