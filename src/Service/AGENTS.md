## Escopo
- Regras operacionais especificas dos services de integracao de marketplace.

## Food99Service.php
- A carteira operacional da integracao deve ser `99 Food`, mesmo quando o app canonico continuar sendo `Food99`.
- Pedidos novos do evento `orderNew` devem gerar o financeiro automaticamente no proprio pipeline de integracao. O endpoint manual de invoices serve apenas para backfill/legado.
- Quando o pedido for pago no app, precisa existir uma invoice explicita do fluxo `cliente -> 99 Food`.
- O pagamento do motoboy em pedidos de entrega pela plataforma deve ser modelado com `payer = 99 Food` e `receiver = motoboy`, criando a pessoa do entregador no sistema quando houver identificacao suficiente.
- Descontos subsidiados pela loja e pela plataforma que ja chegam compensados no repasse devem aparecer como ajustes internos do marketplace, com `payer = 99 Food` e `receiver = 99 Food`, sem criar conta separada da loja por esse desconto.
- O valor que a loja recebe do marketplace deve entrar na invoice semanal unica, com vencimento na quarta-feira. Taxas cobradas da loja ficam em invoices especificas; descontos ja liquidados dentro do repasse seguem a regra de ajuste interno acima.

## iFoodService.php
- A carteira operacional da integracao deve ser `iFood`.
- Quando o pedido for pago no app, precisa existir uma invoice explicita do fluxo `cliente -> iFood`.
- Descontos e ajustes que ja venham liquidos no repasse, sem cobranca separada para a loja, devem ser modelados como fluxo interno do marketplace, com `payer = iFood` e `receiver = iFood`.
- O valor que a loja recebe do marketplace deve entrar na invoice semanal unica, com vencimento na quarta-feira. Taxas cobradas da loja ficam em invoices especificas; descontos ja liquidados dentro do repasse seguem a regra de ajuste interno acima.
- Quando o pedido for pago na entrega, o recebimento do cliente continua separado do repasse semanal do marketplace.
