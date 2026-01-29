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


class Food99Service extends DefaultFoodService
{
    private function init()
    {
        self::$app = 'Food99';
        self::$logger = $this->loggerService->getLogger(self::$app);
        self::$extraFields = $this->extraDataService->discoveryExtraFields('Code', self::$app, '{}', 'code');
        self::$foodPeople = $this->peopleService->discoveryPeople('6012920000123', null, null, '99 Food', 'J');
    }

    public function integrate(Integration $integration): ?Order
    {
        $this->init();
        self::$logger->info('Food99 RAW BODY', [
            'body' => $integration->getBody()
        ]);

        $json = json_decode($integration->getBody(), true);

        self::$logger->info('Food99 JSON DECODE', [
            'json_error' => json_last_error_msg(),
            'has_type' => isset($json['type']),
            'has_data' => isset($json['data']),
            'has_order_items' => isset($json['data']['order_items']),
            'order_items_type' => gettype($json['data']['order_items'] ?? null),
            'order_items_value' => $json['data']['order_items'] ?? null,
        ]);

        if (($json['type'] ?? null) !== 'orderNew') {
            return null;
        }

        return $this->addOrder($json);
    }

    private function addOrder(array $json): ?Order
    {
        $data = $json['data'] ?? [];

        self::$logger->info('Food99 ADD ORDER DATA', [
            'keys' => array_keys($data),
            'order_items_type' => gettype($data['order_items'] ?? null),
        ]);

        $info = $data['order_info'] ?? [];
        $orderId = (string) ($data['order_id'] ?? uniqid());

        $exists = $this->extraDataService->getEntityByExtraData(self::$extraFields, $orderId, Order::class);
        if ($exists) {
            return $exists;
        }

        $provider = $this->extraDataService->getEntityByExtraData(self::$extraFields, $info['shop']['shop_id'], People::class);

        if (!$provider) {
            $provider = $this->peopleService->discoveryPeople(
                null,
                null,
                null,
                $info['shop']['shop_name'],
                'J'
            );
        }

        $client = $this->discoveryClient($data['receive_address'] ?? []);
        $status = $this->statusService->discoveryStatus('pending', 'quote', 'order');

        $order = $this->createOrder($client, $provider, $info['price']['order_price'] ?? 0, $status,   $json);

        $items = $data['order_items'] ?? [];

        self::$logger->info('Food99 BEFORE addProducts', [
            'is_array' => is_array($items),
            'count' => is_array($items) ? count($items) : null,
            'value' => $items
        ]);

        if (is_array($items) && !empty($items)) {
            $this->addProducts($order, $items);
        } else {
            self::$logger->error('Food99 order_items inválido', [
                'order_id' => $orderId,
                'order_items' => $items
            ]);
        }

        $this->addAddress($order, $data['receive_address'] ?? []);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $this->discoveryFoodCode($order, $orderId);
    }

    private function addProducts(
        Order $order,
        array $items,
        ?Product $parentProduct = null,
        ?OrderProduct $orderParentProduct = null
    ) {
        foreach ($items as $item) {
            $productType = $parentProduct ? 'component' : 'product';

            $product = $this->discoveryProduct($order, $item, $parentProduct, $productType);

            $productGroup = null;

            if ($parentProduct && !empty($item['app_content_id'])) {
                $productGroup = $this->productGroupService->discoveryProductGroup(
                    $parentProduct,
                    $item['app_content_id'],
                    $item['content_name'] ?: $item['app_content_id']
                );
            }

            $orderProduct = $this->orderProductService->addOrderProduct(
                $order,
                $product,
                $item['amount'] ?? 1,
                $item['sku_price'] ?? 0,
                $productGroup,
                $parentProduct,
                $orderParentProduct
            );

            if (!empty($item['sub_item_list']) && is_array($item['sub_item_list'])) {
                $this->addProducts(
                    $order,
                    $item['sub_item_list'],
                    $product,
                    $orderProduct
                );
            }
        }
    }

    private function discoveryProduct(
        Order $order,
        array $item,
        ?Product $parentProduct,
        string $productType
    ): Product {
        $code = $item['app_item_id'] ?? uniqid();

        $product = $this->extraDataService->getEntityByExtraData(
            self::$extraFields,
            $code,
            Product::class
        );

        if (!$product) {
            $unity = $this->entityManager
                ->getRepository(ProductUnity::class)
                ->findOneBy(['productUnit' => 'UN']);

            $product = new Product();
            $product->setProduct($item['name'] ?? 'Produto Food99');
            $product->setSku(null);
            $product->setPrice($item['sku_price'] ?? 0);
            $product->setProductUnit($unity);
            $product->setType($productType);
            $product->setProductCondition('new');
            $product->setCompany($order->getProvider());

            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        if ($parentProduct && !empty($item['app_content_id'])) {
            $group = $this->productGroupService->discoveryProductGroup(
                $parentProduct,
                $item['app_content_id'],
                $item['content_name'] ?: $item['app_content_id']
            );

            $exists = $this->entityManager
                ->getRepository(ProductGroupProduct::class)
                ->findOneBy([
                    'product' => $parentProduct,
                    'productChild' => $product,
                    'productGroup' => $group
                ]);

            if (!$exists) {
                $pgp = new ProductGroupProduct();
                $pgp->setProduct($parentProduct);
                $pgp->setProductChild($product);
                $pgp->setProductGroup($group);
                $pgp->setProductType($productType);
                $pgp->setQuantity($item['amount'] ?? 1);
                $pgp->setPrice($item['sku_price'] ?? 0);

                $this->entityManager->persist($pgp);
                $this->entityManager->flush();
            }
        }

        return $this->discoveryFoodCode($product, $code);
    }

    private function discoveryClient(array $address): ?People
    {
        if (empty($address['name'])) {
            return null;
        }

        $client = $this->peopleService->discoveryPeople(
            $address['uid'] ?? null,
            null,
            null,
            $address['name']
        );

        return $this->discoveryFoodCode(
            $client,
            (string) ($address['uid'] ?? uniqid())
        );
    }

    private function addAddress(Order $order, array $address)
    {
        if (!$address) {
            return;
        }

        $addr = $this->addressService->discoveryAddress(
            $order->getClient(),
            $address['postal_code'] ?? null,
            $address['street_number'] ?? null,
            $address['street_name'] ?? null,
            $address['district'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['country_code'] ?? null,
            $address['complement'] ?? null,
            $address['poi_lat'] ?? 0,
            $address['poi_lng'] ?? 0
        );

        $order->setAddressDestination($addr);
    }
}
