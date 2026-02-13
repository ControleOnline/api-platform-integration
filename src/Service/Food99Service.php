<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;

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

        $data  = is_array($json) ? ($json['data'] ?? []) : [];
        $info  = is_array($data) ? ($data['order_info'] ?? []) : [];
        $items = is_array($info) ? ($info['order_items'] ?? null) : null;

        self::$logger->info('Food99 JSON DECODE', [
            'json_error' => json_last_error_msg(),
            'has_type' => is_array($json) && isset($json['type']),
            'has_data' => is_array($json) && isset($json['data']),
            'has_order_info' => is_array($data) && isset($data['order_info']),
            'has_order_items' => isset($items) || (is_array($data) && isset($data['order_items'])),
            'order_items_path' => isset($items) ? 'data.order_info.order_items' : (isset($data['order_items']) ? 'data.order_items' : null),
            'order_items_type' => gettype($items ?? ($data['order_items'] ?? null)),
        ]);

        if (!is_array($json)) {
            return null;
        }

        if (($json['type'] ?? null) !== 'orderNew') {
            return null;
        }

        return $this->addOrder($json);
    }

    private function addOrder(array $json): ?Order
    {
        $data = $json['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $info = $data['order_info'] ?? [];
        if (!is_array($info)) {
            $info = [];
        }

        // Fallbacks (alguns webhooks podem mandar fora do order_info)
        $shop  = $info['shop']  ?? ($data['shop']  ?? []);
        $price = $info['price'] ?? ($data['price'] ?? []);

        if (!is_array($shop))  $shop = [];
        if (!is_array($price)) $price = [];

        // order_items: PRIORIDADE no order_info
        $items = $info['order_items'] ?? ($data['order_items'] ?? []);
        if (!is_array($items)) {
            $items = [];
        }

        // receive_address: PRIORIDADE no order_info
        $receiveAddress = $info['receive_address'] ?? ($data['receive_address'] ?? []);
        if (!is_array($receiveAddress)) {
            $receiveAddress = [];
        }

        self::$logger->info('Food99 ADD ORDER DATA', [
            'keys' => array_keys($data),
            'has_order_info' => !empty($info),
            'order_items_type' => gettype($items),
            'order_items_count' => is_array($items) ? count($items) : null,
        ]);

        $orderId = (string)($data['order_id'] ?? ($info['order_id'] ?? uniqid()));

        $exists = $this->extraDataService->getEntityByExtraData(self::$extraFields, $orderId, Order::class);
        if ($exists) {
            return $exists;
        }

        $shopId = $shop['shop_id'] ?? null;

        $provider = null;
        if ($shopId) {
            $provider = $this->extraDataService->getEntityByExtraData(self::$extraFields, $shopId, People::class);
        }

        if (!$provider) {
            $provider = $this->peopleService->discoveryPeople(
                null,
                null,
                null,
                $shop['shop_name'] ?? 'Loja Food99',
                'J'
            );
        }

        $client = $this->discoveryClient($receiveAddress);
        $status = $this->statusService->discoveryStatus('pending', 'quote', 'order');

        $orderPrice = $price['order_price'] ?? 0;

        $order = $this->createOrder($client, $provider, $orderPrice, $status, $json);

        self::$logger->info('Food99 BEFORE addProducts', [
            'is_array' => is_array($items),
            'count' => is_array($items) ? count($items) : null,
            'value_preview' => is_array($items) ? array_slice($items, 0, 2) : null
        ]);

        if (!empty($items)) {
            $this->addProducts($order, $items);
        } else {
            self::$logger->error('Food99 order_items inválido', [
                'order_id' => $orderId,
                'order_items' => $items
            ]);
        }

        // NÃO quebrar se vier sem endereço completo
        $this->addAddress($order, $receiveAddress);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->printOrder($order);
        return $this->discoveryFoodCode($order, $orderId);
    }

    private function addProducts(
        Order $order,
        array $items,
        ?Product $parentProduct = null,
        ?OrderProduct $orderParentProduct = null
    ) {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

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

        // Se não tem CEP, não chama AddressService (ele exige int e quebra com null)
        $rawPostal = $address['postal_code'] ?? null;
        $postalCode = $rawPostal !== null ? (int) preg_replace('/\D+/', '', (string) $rawPostal) : 0;

        if ($postalCode <= 0) {
            self::$logger->warning('Food99 address missing/invalid postal_code (skipping address)', [
                'postal_code' => $rawPostal,
                'address_keys' => array_keys($address),
            ]);
            return;
        }

        $addr = $this->addressService->discoveryAddress(
            $order->getClient(),
            $postalCode,
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
