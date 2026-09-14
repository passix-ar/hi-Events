---
title: Webhooks
description: Recibí notificaciones en tiempo real de eventos en Passix para integrar con tus sistemas.
sidebar:
  order: 2
---

Los **webhooks** te permiten enterarte al instante de lo que pasa en Passix (una venta, un check-in, un reembolso) sin estar consultando la [API](/tecnico/api/) todo el tiempo. Son ideales para integrar con tu CRM, herramientas de marketing o bases de datos.

![Configuración de webhooks](/img/panel/ev-webhooks.png)

## Cómo funcionan

1. Configurás una **URL** tuya (endpoint HTTPS).
2. Cuando ocurre un evento suscripto, Passix le hace un **POST** con los datos en JSON.
3. Tu servidor lo procesa (guarda la venta, dispara un email, etc.).

## Configurar un webhook

1. Andá a **Webhooks** (a nivel **organización** para integraciones globales, o a nivel **evento** para automatizaciones puntuales).
2. Hacé clic en **Crear webhook**.
3. Ingresá la **URL de tu endpoint**.
4. Seleccioná los **eventos** a los que querés suscribirte.
5. Guardá.

## Eventos soportados

| Evento | Cuándo se dispara |
|---|---|
| `product.created` · `product.updated` · `product.deleted` | Cambios en entradas/productos. |
| `event.created` · `event.updated` · `event.archived` | Cambios en el evento. |
| `order.created` | Se creó una orden (puede estar pendiente de pago). |
| `order.updated` | Se actualizó una orden. |
| `order.marked_as_paid` | Una orden se marcó como pagada. |
| `order.refunded` | Se reembolsó una orden (total o parcial). |
| `order.cancelled` | Se canceló una orden. |
| `attendee.created` · `attendee.updated` · `attendee.cancelled` | Cambios en un asistente. |
| `checkin.created` | Se validó un asistente en la puerta. |
| `checkin.deleted` | Se deshizo un check-in. |

## Estructura del payload

Cada notificación es un JSON con esta forma general:

```json
{
  "event": "order.marked_as_paid",
  "timestamp": "2026-07-01T10:30:00Z",
  "data": {
    "order_id": "ORD-12345",
    "event_id": "EVT-67890",
    "total_amount": 15000,
    "currency": "ARS",
    "buyer_email": "comprador@ejemplo.com",
    "tickets": [
      { "ticket_id": "TKT-111", "type": "General", "price": 10000 },
      { "ticket_id": "TKT-222", "type": "VIP", "price": 5000 }
    ]
  }
}
```

## Seguridad

Cada webhook tiene un **secreto (secret)** que se genera al crearlo. Usalo en tu endpoint para verificar que la notificación viene de Passix y no de un tercero. Guardá ese secreto de forma segura.

## Buenas prácticas

- Respondé rápido con `2xx`; procesá lo pesado en segundo plano.
- Hacé tu endpoint **idempotente**: un mismo evento puede llegar más de una vez.
- Si tu endpoint falla, Passix **reintenta** la entrega.

## Testing

Durante el desarrollo podés usar herramientas como **Webhook.site** o **ngrok** para inspeccionar los envíos y probar tu endpoint.

:::note
Si solo querés leer datos bajo demanda, usá la [API pública](/tecnico/api/). Si querés reaccionar en tiempo real, usá webhooks.
:::
