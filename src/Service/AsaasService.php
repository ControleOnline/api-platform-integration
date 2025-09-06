<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Card;
use ControleOnline\Entity\Config;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
as Security;
use ControleOnline\Service\PeopleRoleService;
use ControleOnline\Service\WalletService;
use DateTime;
use GuzzleHttp\Client;

class AsaasService
{
    private $entryPoint = 'https://api.asaas.com/v3/';
    private $client;
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleRoleService $peopleRoleService,
        private DomainService $domainService,
        private PeopleService $peopleService,
        private InvoiceService $invoiceService,
        private OrderService $orderService,
        private WalletService $walletService,
        private StatusService $statusService,

    ) {}

    private function getApiKey(People $people)
    {

        $asaasKey = $this->manager->getRepository(Config::class)->findOneBy([
            'people' => $people,
            'configKey' => 'asaas-key'
        ]);

        if (!$asaasKey) throw new \Exception('Asaas key not found');

        return $asaasKey->getConfigValue();
    }

    private function init(People $people)
    {
        if ($this->client)
            return $this->client;

        $this->client = new Client([
            'base_uri' => $this->entryPoint,
            'headers' => [
                'Accept' => 'application/json',
                'access_token' =>  $this->getApiKey($people),
                'Content-Type' => 'application/json',
            ]
        ]);

        $this->discoveryWebhook($people);
    }

    public function discoveryWebhook(People $people)
    {
        $response = $this->client->request('GET', 'webhooks');
        $webhook =  json_decode($response->getBody()->getContents(), true);
        $url = "https://" . $this->domainService->getMainDomain() . "/webhook/asaas/return/" . $people->getId();

        if ($webhook['totalCount'] != 0 || $webhook['data'][0]['url'] == $url)
            return;

        $response = $this->client->request('POST', 'webhooks', [
            'json' => [
                "name" => "Controle Online",
                "url" => $url,
                "email" => "luiz.kim@controleonline.com",
                "enabled" => true,
                "interrupted" => false,
                "sendType" => "NON_SEQUENTIALLY",
                "authToken" => $this->getWebhookApiKey($people),
                "events" => [
                    'PAYMENT_CREATED',
                    //  'PAYMENT_AWAITING_RISK_ANALYSIS',
                    //  'PAYMENT_APPROVED_BY_RISK_ANALYSIS',
                    //  'PAYMENT_REPROVED_BY_RISK_ANALYSIS',
                    //  'PAYMENT_AUTHORIZED',
                    //  'PAYMENT_UPDATED',
                    //  'PAYMENT_CONFIRMED',
                    //  'PAYMENT_RECEIVED',
                    //  'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED',
                    //  'PAYMENT_ANTICIPATED',
                    //  'PAYMENT_OVERDUE',
                    //  'PAYMENT_DELETED',
                    //  'PAYMENT_RESTORED',
                    //  'PAYMENT_REFUNDED',
                    //  'PAYMENT_PARTIALLY_REFUNDED',
                    //  'PAYMENT_REFUND_IN_PROGRESS',
                    //  'PAYMENT_RECEIVED_IN_CASH_UNDONE',
                    //  'PAYMENT_CHARGEBACK_REQUESTED',
                    //  'PAYMENT_CHARGEBACK_DISPUTE',
                    //  'PAYMENT_AWAITING_CHARGEBACK_REVERSAL',
                    //  'PAYMENT_DUNNING_RECEIVED',
                    //  'PAYMENT_DUNNING_REQUESTED',
                    //  'PAYMENT_BANK_SLIP_VIEWED',
                    //  'PAYMENT_CHECKOUT_VIEWED',
                    //  'PAYMENT_SPLIT_CANCELLED',
                    //  'PAYMENT_SPLIT_DIVERGENCE_BLOCK',
                    //  'PAYMENT_SPLIT_DIVERGENCE_BLOCK_FINISHED',
                    //  'TRANSFER_CREATED',
                    //  'TRANSFER_PENDING',
                    //  'TRANSFER_IN_BANK_PROCESSING',
                    //  'TRANSFER_BLOCKED',
                    //  'TRANSFER_DONE',
                    //  'TRANSFER_FAILED',
                    //  'TRANSFER_CANCELLED',
                    //  'BILL_CREATED',
                    //  'BILL_PENDING',
                    //  'BILL_BANK_PROCESSING',
                    //  'BILL_PAID',
                    //  'BILL_CANCELLED',
                    //  'BILL_FAILED',
                    //  'BILL_REFUNDED'
                ]
            ],

        ]);
    }


    public function payWithCard(Invoice $invoice, Card $card): Invoice
    {

        $this->init($invoice->getReceiver());
        $userPeople =  $this->security->getToken()->getUser()->getPeople();
        $customer = $this->discoveryCustomer($userPeople);

        $data = [
            "customer" =>  $customer['id'],
            "billingType" => 'CREDIT_CARD',
            "remoteIp" => '200.187.64.87',
            "value" => $invoice->getPrice(),
            "dueDate" => $invoice->getDueDate()->format('Y-m-d'),
            "daysAfterDueDateToRegistrationCancellation" => 10,
            "externalReference" => $invoice->getId(),
            "creditCard" => [
                "holderName" => $card->getName(),
                "number" =>  $card->getNumberGroup1() . $card->getNumberGroup2() . $card->getNumberGroup3() . $card->getNumberGroup4(),
                "expiryMonth" => $card->getExpirationMonth(),
                "expiryYear" => $card->getExpirationYear(),
                "ccv" => $card->getCcv(),
            ]
        ];

        $response = json_decode($this->client->request('POST', 'payments/', [
            'json' => $data
        ])->getBody()->getContents(), true);

        dd($response);

        return $invoice;
    }

    public function discoveryCustomer(People $people)
    {
        $response = $this->client->request('GET',  'customers', ['query' => ['cpfCnpj' => '32115692861']]);
        $customer = json_decode($response->getBody()->getContents(), true);

        if ($customer['totalCount'] > 0)
            return $customer['data'][0];
    }

    public function getClient($client_id)
    {
        $response = $this->client->request('GET',  'customers/' . $client_id, []);
        return json_decode($response->getBody()->getContents(), true);
    }

    private function getWebhookApiKey(People $people)
    {
        return md5($this->getApiKey($people));
    }



    public function integrate(Integration $integration)
    {
        $receiver =  $integration->getPeople();
        $json = json_decode($integration->getBody(), true);
        $this->init($receiver);

        switch ($json["event"]) {
            case 'PAYMENT_CREATED':
                $client = $this->getClient($json['payment']['customer']);
                $payer = $this->peopleService->discoveryPeople(
                    $client['cpfCnpj'],
                    null,
                    null,
                    $client['name'],
                    $client['personType'] == 'FISICA' ? 'F' : 'J',
                );

                return $this->invoiceService->createInvoiceByOrder(
                    $this->orderService->createOrder($receiver, $payer, 'Asaas'),
                    $json['payment']['value'],
                    null,
                    new DateTime($json['payment']['dueDate']),
                    $this->walletService->discoverWallet($receiver, 'Asaas'),
                );

                break;

            default:
                return null;
                break;
        }
    }

    public function getPix(Invoice $invoice)
    {
        $this->init($invoice->getReceiver());
        $receiver = $invoice->getReceiver();
        $pixKey = $this->manager->getRepository(Config::class)->findOneBy([
            'people' => $receiver,
            'configKey'  => 'asaas-receiver-pix-key'
        ]);

        if (!$pixKey) throw new \Exception('Pix key not found');

        $response = $this->client->request('POST',  'pix/qrCodes/static', [
            'json' => [
                "addressKey" => $pixKey->getConfigValue(),
                "value" => $invoice->getPrice(),
                "allowsMultiplePayments" => false,
                "externalReference" => $invoice->getId()
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }
}
