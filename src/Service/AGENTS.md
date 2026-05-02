## Escopo
- Regras operacionais especificas dos services de integracao de marketplace.

## Food99Service.php
- A carteira operacional da integracao deve ser `99 Food`, mesmo quando o app canonico continuar sendo `Food99`.
- O pagamento do motoboy em pedidos de entrega pela plataforma deve ser modelado com `payer = 99 Food` e `receiver = motoboy`, criando a pessoa do entregador no sistema quando houver identificacao suficiente.
- O valor que a loja recebe do marketplace deve entrar na invoice semanal unica, com vencimento na quarta-feira, e os descontos/taxas da loja devem aparecer como invoices separadas de compensacao.

## iFoodService.php
- A carteira operacional da integracao deve ser `iFood`.
- O valor que a loja recebe do marketplace deve entrar na invoice semanal unica, com vencimento na quarta-feira, e os descontos/taxas da loja devem aparecer como invoices separadas de compensacao.
- Quando o pedido for pago na entrega, o recebimento do cliente continua separado do repasse semanal do marketplace.
