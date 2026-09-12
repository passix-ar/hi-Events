---
title: Pago offline (transferencia / efectivo)
description: Aceptá transferencias o efectivo y confirmá los pagos manualmente.
sidebar:
  order: 3
---

El **pago offline** te permite aceptar transferencias bancarias, efectivo u otro medio por fuera de MercadoPago. Vos confirmás el pago a mano cuando lo recibís.

También es la salida cuando MercadoPago no está disponible: sirve para **publicar un evento** sin tener MercadoPago conectado, y para **destrabar la desconexión** de MercadoPago en eventos ya publicados.

## Activarlo

1. En la [configuración del evento](/evento/configuracion/), habilitá **pago offline**.
2. Cargá las **instrucciones** que verá el comprador (CBU/alias, datos de transferencia, contacto).

## Cómo funciona

1. El comprador elige "pago offline" en el checkout.
2. Se genera una orden en estado **esperando pago**.
3. El comprador recibe las **instrucciones** y te transfiere/paga.
4. Cuando confirmás que recibiste el dinero, marcás la orden como **pagada**.
5. Recién ahí el asistente recibe su entrada con QR.

## Confirmar un pago

1. Andá a [Órdenes](/ventas/ordenes/).
2. Abrí la orden en estado *esperando pago*.
3. **Marcar como pagada**.

:::caution
Con pago offline, la entrada **no se emite** hasta que confirmás el pago. Definí un plazo razonable y cancelá las órdenes que no se paguen para liberar el stock.
:::

:::tip
Para eventos con mucho volumen, MercadoPago es más cómodo: se confirma solo. Reservá el offline para casos puntuales (mayoristas, cortesías, acuerdos especiales).
:::
