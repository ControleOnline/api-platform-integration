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
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class iFoodService extends DefaultFoodService implements EventSubscriberInterface
{
    // INICIALIZAÇÃO
    // Define constantes: app name, logger e entidade padrão do iFood
    private function init()
    {
        self::$app = 'iFood';
        self::$logger = $this->loggerService->getLogger(self::$app);
        self::$foodPeople = $this->peopleService->discoveryPeople('14380200000121', null, null, 'Ifood.com Agência de Restaurantes Online S.A', 'J');
    }

    // PONTO DE ENTRADA DO WEBHOOK
    // Recebe webhook do iFood, decodifica JSON e roteia para ação correta (PLACED ou CANCELLED)
    public function integrate(Integration $integration)
    {
        $this->init();

        $json = json_decode($integration->getBody(), true);

        $fullCode = $json['fullCode'];
        $this->addLog('info', 'Código recebido', ['code' => $json['fullCode']]);

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

    // CANCELAMENTO DE PEDIDO
    // Busca pedido pelo orderId do iFood, marca como cancelado e atualiza banco
    private function cancelOrder(array $json): ?Order
    {
        $orderId = $json['orderId'] ?? null;

        $order = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $orderId, Order::class);
        if ($order) {
            $status = $this->statusService->discoveryStatus('canceled', 'canceled', 'order');

            $other = (array) $order->getOtherInformations(true);
            $other[$json['fullCode']] = $json;
            $order->addOtherInformations(self::$app, $other);
            $order->setStatus($status);
            $this->entityManager->persist($order);
            $this->entityManager->flush();
            //@todo cancelar faturas
            return $order;
        }
        return null;
    }

    // CRIAÇÃO DE NOVO PEDIDO
    // Valida IDs, busca restaurante e cliente, pega detalhes do iFood,
    // cria pedido com produtos, entrega e pagamentos, grava no banco
    private function addOrder(array $json): ?Order
    {

        $orderId = $json['orderId'] ?? null;
        $merchantId = $json['merchantId'] ?? null;

        if (!$orderId || !$merchantId) {
            $this->addLog('error', 'Dados do pedido incompletos', ['json' => $json]);
            return null;
        }

        $provider = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $merchantId, People::class);
        $order = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $orderId, Order::class);
        if ($order)
            return $order;

        $orderDetails = $this->fetchOrderDetails($orderId);
        if (!$orderDetails) {
            $this->addLog('error', 'Não foi possível obter detalhes do pedido', ['orderId' => $orderId]);
            return null;
        }

        $json['order'] = $orderDetails;
        $status = $this->statusService->discoveryStatus('pending', 'quote', 'order');
        $client = $this->discoveryClient($provider, $orderDetails['customer'] ?? []);

        $order = $this->createOrder($client, $provider, $orderDetails['total']['orderAmount'] ?? 0, $status, [$json['fullCode'] => $json]);

        $this->addProducts($order, $orderDetails['items']);
        $this->addDelivery($order, $orderDetails);
        $this->addPayments($order, $orderDetails);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->addLog('info', 'Pedido processado com sucesso', ['orderId' => $orderId]);

        $this->printOrder($order);
        return $this->discoveryFoodCode($order, $orderId);
    }

    // FATURAS DE RECEBIMENTO (Pagamentos)
    // Para cada método de pagamento, cria fatura de recebimento no banco
    private function addReceiveInvoices(Order $order, array $payments)
    {
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        foreach ($payments as $payment)
            $this->invoiceService->createInvoiceByOrder($order, $payment['value'], $payment['prepaid'] ? $status : null, new DateTime(), null, $iFoodWallet);
    }

    // ENTREGA
    // Define endereço de entrega e, se entrega for por terceiros, cria taxa de entrega
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

    // TAXA DE ENTREGA
    // Cria fatura para taxa de entrega (cobrada do restaurante para o iFood)
    private function addDeliveryFee(Order &$order, array $payments)
    {
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        $order->setRetrieveContact(self::$foodPeople);

        $this->invoiceService->createInvoice(
            $order,
            $order->getProvider(),
            self::$foodPeople,
            $payments['deliveryFee'],
            $status,
            new DateTime(),
            $iFoodWallet,
            $iFoodWallet
        );
    }

    // TAXAS ADICIONAIS
    // Cria fatura para taxas/comissões do iFood
    private function addFees(Order $order, array $payments)
    {
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
        $this->invoiceService->createInvoice(
            $order,
            $order->getProvider(),
            self::$foodPeople,
            $payments['additionalFees'],
            $status,
            new DateTime(),
            $iFoodWallet,
            $iFoodWallet
        );
    }

    // PAGAMENTOS
    // Agrupa todas as operações de pagamento: faturas de recebimento e taxas
    private function addPayments(Order $order, array $orderDetails)
    {
        $this->addReceiveInvoices($order, $orderDetails['payments']['methods']);
        $this->addFees($order, $orderDetails['total']);
    }

    // PRODUTOS
    // Percorre itens do pedido recursivamente, criando produtos e relacionamentos
    // Trata produtos, opções e customizações como componentes hierárquicos
    private function addProducts(Order $order, array $items, ?Product $parentProduct = null, ?OrderProduct $orderParentProduct = null, ?string $productType = 'product')
    {
        foreach ($items as $item) {

            if ((isset($item['options']) && $item['options']) || (isset($item['customizations']) && $item['customizations']))
                $productType = 'custom';

            $product = $this->discoveryProduct($order, $item, $parentProduct, $productType);
            $productGroup = null;
            if (isset($item['groupName']))
                $productGroup = $this->productGroupService->discoveryProductGroup($parentProduct ?: $product, $item['groupName']);
            $orderProduct = $this->orderProductService->addOrderProduct($order, $product, $item['quantity'], $item['unitPrice'], $productGroup, $parentProduct, $orderParentProduct);
            if (isset($item['options']) && $item['options'])
                $this->addProducts($order, $item['options'], $product, $orderProduct, 'component');
            if (isset($item['customizations']) && $item['customizations'])
                $this->addProducts($order, $item['customizations'], $product, $orderProduct, 'component');
        }
    }

    // TOKEN OAUTH
    // Autentica na API do iFood e retorna token de acesso
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

    // FETCH DETALHES DO PEDIDO
    // Chama API do iFood para buscar informações completas do pedido (cliente, produtos, entrega, pagamentos)
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
                    'Authorization' => 'Bearer ' . $token,
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

    // DESCOBERTA/CRIAÇÃO DO CLIENTE
    // Busca cliente existente pelo ID do iFood ou cria novo com dados do pedido
    private function discoveryClient(People $provider, array $customerData): ?People
    {
        if (empty($customerData['name']) || empty($customerData['phone'])) {
            self::$logger->warning('Dados do cliente incompletos', ['customer' => $customerData]);
            return null;
        }

        $codClienteiFood = $customerData['id'];

        $client = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $codClienteiFood, People::class);

        $phone = [
            'ddd' => '11',
            'phone' => $customerData['phone']['number']
        ];

        $document = $customerData['documentNumber'];

        if (!$client)
            $client = $this->peopleService->discoveryPeople($document, null, $phone, $customerData["name"]);

        $this->peopleService->discoveryClient($provider, $client);

        return $this->discoveryFoodCode($client, $codClienteiFood);
    }

    // DESCOBERTA/CRIAÇÃO DO PRODUTO
    // Busca produto existente por múltiplas chaves (iFood ID, código externo, EAN, nome)
    // Se não encontrar, cria novo produto. Se tem pai, associa como grupo/componente
    private function discoveryProduct(Order $order, array $item, ?Product $parentProduct = null, string $productType = 'product'): Product
    {
        $codProductiFood = $item['id'];
        $product = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $codProductiFood, Product::class);

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
                $productGroup = $this->productGroupService->discoveryProductGroup($parentProduct, $item['groupName']);
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

        return $this->discoveryFoodCode($product, $codProductiFood);
    }

    // ESCUTA DE MUDANÇAS DE ENTIDADE
    // Registra a classe como listener de eventos de mudança de entidade
    public static function getSubscribedEvents(): array
    {
        return [
            EntityChangedEvent::class => 'onEntityChanged',
        ];
    }

    // HANDLER DE MUDANÇA DE ENTIDADE
    // Quando um pedido do iFood muda de status, dispara sincronização com o iFood
    public function onEntityChanged(EntityChangedEvent $event)
    {
        $oldEntity = $event->getOldEntity();
        $entity = $event->getEntity();

        if (!$entity instanceof Order || !$oldEntity instanceof Order)
            return;

        $this->init();
        if ($entity->getApp() !== self::$app)
            return;

        if ($oldEntity->getStatus()->getId() != $entity->getStatus()->getId())
            $this->changeStatus($entity);
    }

    // SINCRONIZAÇÃO DE STATUS COM iFOOD
    // Envia para iFood o novo status do pedido (pronto, entregue, cancelado)
    public function changeStatus(Order $order)
    {
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
}