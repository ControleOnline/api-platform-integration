<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;

class Food99Service
{
    private static $extraFields;
    private static $people99;
    protected static $logger;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerService $loggerService,
        private ExtraDataService $extraDataService,
        private PeopleService $peopleService,
        private OrderService $orderService,
        private StatusService $statusService,
        private AddressService $addressService,
        private WalletService $walletService,
        private OrderProductService $orderProductService
    ) {
        self::$logger = $this->loggerService->getLogger('Food99');
        self::$extraFields = $this->extraDataService->discoveryExtraFields('Code', 'Food99', '{}', 'code');
        self::$people99 = $this->peopleService->discoveryPeople('Food99', null, null, 'Food99', 'J');
    }

    public function integrate(Integration $integration): ?Order
    {
        $json = json_decode($integration->getBody(), true);

        if (($json['type'] ?? null) !== 'orderNew')
            return null;

        return $this->addOrder($json);
    }

    private function addOrder(array $json): ?Order
    {
        $data = $json['data'];
        $info = $data['order_info'];
        $orderId = (string) $data['order_id'];

        $exists = $this->extraDataService->getEntityByExtraData(self::$extraFields, $orderId, Order::class);
        if ($exists)
            return $exists;

        $provider = $this->extraDataService->getEntityByExtraData(
            self::$extraFields,
            (string) $info['shop']['shop_id'],
            People::class
        );

        if (!$provider)
            $provider = $this->peopleService->discoveryPeople(
                $info['shop']['shop_id'],
                null,
                null,
                $info['shop']['shop_name'],
                'J'
            );

        $client = $this->discoveryClient($data['receive_address'] ?? []);
        $status = $this->statusService->discoveryStatus('pending', 'quote', 'order');

        $order = new Order();
        $order->setClient($client);
        $order->setProvider($provider);
        $order->setPayer($client);
        $order->setStatus($status);
        $order->setAlterDate(new DateTime());
        $order->setApp('Food99');
        $order->setOrderType('sale');
        $order->setUser($this->getApiUser());
        $order->setPrice($info['price']['order_price']);
        $order->addOtherInformations('Food99', $json);

        $this->entityManager->persist($order);

        $this->addProducts($order, $data['order_items']);
        $this->addAddress($order, $data['receive_address'] ?? []);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $this->discoveryFood99Code($order, $orderId);
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
                $productGroup = $this->discoveryProductGroup(
                    $parentProduct,
                    $item['app_content_id'],
                    $item['content_name'] ?: $item['app_content_id']
                );
            }

            $orderProduct = $this->orderProductService->addOrderProduct(
                $order,
                $product,
                $item['amount'],
                $item['sku_price'],
                $productGroup,
                $parentProduct,
                $orderParentProduct
            );

            if (!empty($item['sub_item_list'])) {
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
        $code = $item['app_item_id'];

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
            $product->setProduct($item['name']);
            $product->setSku(null);
            $product->setPrice($item['sku_price']);
            $product->setProductUnit($unity);
            $product->setType($productType);
            $product->setProductCondition('new');
            $product->setCompany($order->getProvider());

            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        if ($parentProduct && !empty($item['app_content_id'])) {

            $group = $this->discoveryProductGroup(
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
                $pgp->setQuantity($item['amount']);
                $pgp->setPrice($item['sku_price']);

                $this->entityManager->persist($pgp);
                $this->entityManager->flush();
            }
        }

        return $this->discoveryFood99Code($product, $code);
    }

    private function discoveryProductGroup(
        Product $parentProduct,
        string $groupCode,
        string $groupName
    ): ProductGroup {
        $group = $this->entityManager
            ->getRepository(ProductGroup::class)
            ->findOneBy([
                'parentProduct' => $parentProduct,
                'productGroup' => $groupCode
            ]);

        if ($group)
            return $group;

        $group = new ProductGroup();
        $group->setParentProduct($parentProduct);
        $group->setProductGroup($groupCode);
        $group->setPriceCalculation('sum');
        $group->setRequired(false);
        $group->setMinimum(0);
        $group->setMaximum(0);
        $group->setActive(true);
        $group->setGroupOrder(0);

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }

    private function discoveryClient(array $address): ?People
    {
        if (empty($address['name']))
            return null;

        $client = $this->peopleService->discoveryPeople(
            $address['uid'] ?? null,
            null,
            null,
            $address['name']
        );

        return $this->discoveryFood99Code(
            $client,
            (string) ($address['uid'] ?? uniqid())
        );
    }

    private function addAddress(Order $order, array $address)
    {
        if (!$address)
            return;

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

    private function getApiUser(): User
    {
        return $this->entityManager
            ->getRepository(User::class)
            ->find(7);
    }

    private function discoveryFood99Code(object $entity, string $code)
    {
        return $this->extraDataService->discoveryExtraData(
            $entity->getId(),
            self::$extraFields,
            $code,
            $entity
        );
    }
}
