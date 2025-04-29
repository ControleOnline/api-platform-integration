<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\Status;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;

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

    ) {
        self::$logger = $loggerService->getLogger('iFood');
        self::$extraFields = $this->extraDataService->discoveryExtraFields('Code', 'iFood', '{}', 'code');
    }

    public  function integrate(Integration $integration)
    {

        $json = json_decode($integration->getBody(), true);

        $fullCode = $json['fullCode'];

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

    private function addOrder(array $json): ?Order
    {

        $orderId =  $json['orderId'] ?? null;
        $merchantId = $json['merchantId'] ?? null;

        if (!$orderId || !$merchantId) {
            self::$logger->error('Dados do pedido incompletos', ['json' =>  $json]);
            return null;
        }

        $provider = $this->extraDataService->getEntityByExtraData(self::$extraFields, $merchantId, People::class);
        $order = $this->extraDataService->getEntityByExtraData(self::$extraFields, $orderId, Order::class);
        if ($order)
            return $order;

        // Buscar detalhes do pedido via API
        $orderDetails = $this->fetchOrderDetails($orderId);
        if (!$orderDetails) {
            self::$logger->error('Não foi possível obter detalhes do pedido', ['orderId' => $orderId]);
            return null;
        }

        $status = $this->statusService->discoveryStatus('pending', 'quote', 'order');
        $deliveryAddress = $this->discoveryAddress($orderDetails['delivery'] ?? []);
        $client = $this->discoveryClient($orderDetails['customer'] ?? []);

        $order = new Order();
        $order->setClient($client);
        $order->setProvider($provider);
        $order->setStatus($status);
        $order->setAlterDate(new \DateTimeImmutable());
        $order->setApp('iFood');
        $order->setOrderType('sale');
        $order->setAddressDestination($deliveryAddress);
        $order->addOtherInformations('iFood', [$json['fullCode'] => $json]);
        $totalPrice = $orderDetails['total']['orderAmount'] ?? 0;
        $order->setPrice($totalPrice);

        $this->addProducts($order, $orderDetails['items']);
        $this->addPayments($order, $orderDetails['payments'], $orderDetails['total']);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        self::$logger->info('Pedido processado com sucesso', ['orderId' => $orderId]);

        return $order;
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

    private function fetchOrderDetails(string $orderId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://merchant-api.ifood.com.br/order/v1.0/orders/' . $orderId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $_ENV['IFOOD_TOKEN'],
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                self::$logger->error('Erro na API do iFood', ['status' => $response->getStatusCode()]);
                return null;
            }

            return $response->toArray();
        } catch (\Exception $e) {
            self::$logger->error('Erro ao buscar detalhes do pedido', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function discoveryClient(array $customerData): ?People
    {
        if (empty($customerData['name']) || empty($customerData['phone'])) {
            self::$logger->warning('Dados do cliente incompletos', ['customer' => $customerData]);
            return null;
        }

        $phone = $customerData['phone']['number'] ?? $customerData['phone'];
        $client = $this->entityManager->getRepository(People::class)->findOneBy(['phone' => $phone]);

        if (!$client) {
            $client = new People();
            // @todo Criar CLiente
        }

        return $client;
    }



    private function discoveryAddress(array $deliveryData): ?Address
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
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['name' => $itemData['name']]);

        if (!$product) {
            $product = new Product();
            // @todo Criar Produto
        }

        return $product;
    }
}
