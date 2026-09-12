---
title: Emails automáticos y plantillas
description: Qué emails manda Passix solo, cómo personalizarlos con tu texto y qué variables podés usar.
sidebar:
  order: 7
---

Además de los [mensajes que enviás a mano](/ventas/mensajes/), Passix manda **emails automáticos** en cada compra. Podés dejarlos como vienen o reescribirlos con tu texto y tu tono.

## Los emails automáticos

| Email | A quién le llega | Cuándo |
|---|---|---|
| **Confirmación de pedido** | Al comprador | Cuando hace la compra |
| **Entrada del asistente** | A cada asistente | Con su ticket y su QR |

Los dos salen sin que tengas que hacer nada. Personalizarlos es opcional.

## Dónde se personalizan (y cuál gana)

Las plantillas se pueden definir en **dos niveles**, y el más específico gana:

```
Plantilla de Passix          (la que viene por defecto)
  └── Plantilla de tu organización   (reemplaza a la anterior en todos tus eventos)
        └── Plantilla del evento     (reemplaza a las dos, solo en ese evento)
```

- Para todos tus eventos: **configuración de la organización** → plantillas de email.
- Para un evento puntual: dentro del evento, **Configuración** → ajustes de email.

Si un nivel no tiene plantilla propia, se usa la del nivel de arriba. En el panel te lo aclara: *"Se usará la plantilla del organizador o la predeterminada"*.

## Crear una plantilla

1. Elegí el tipo (**Confirmación de pedido** o **Entrada del asistente**).
2. Escribí el **asunto** y el **cuerpo**. Podés partir de la plantilla por defecto y editarla.
3. Insertá las **variables** que necesites (ver abajo).
4. Usá la **vista previa** para ver cómo queda con datos de ejemplo.
5. Guardá.

Podés **desactivar** una plantilla sin borrarla: vuelve a usarse la del nivel de arriba.

:::caution
Las plantillas usan **Liquid**. Si escribís mal una variable (una llave sin cerrar, por ejemplo), Passix **no te deja guardar** y te avisa que la sintaxis es inválida. Es a propósito: una plantilla rota significaría emails rotos para todos tus compradores.
:::

## Variables disponibles

Se escriben entre llaves dobles y se reemplazan al enviar.

### Del evento

| Variable | Qué trae |
|---|---|
| `{{ event.title }}` | Nombre del evento |
| `{{ event.date }}` · `{{ event.time }}` | Fecha y hora de inicio |
| `{{ event.end_date }}` · `{{ event.end_time }}` | Fecha y hora de fin |
| `{{ event.full_address }}` | Dirección completa |
| `{{ event.location_details.venue_name }}` | Nombre del lugar |
| `{{ event.location_details.city }}` | Ciudad |
| `{{ event.description }}` | Descripción del evento |

También están disponibles `state_or_region`, `zip_or_postal_code` y `country` dentro de `location_details`.

### De la organización

| Variable | Qué trae |
|---|---|
| `{{ organizer.name }}` | Nombre de tu organización |
| `{{ organizer.email }}` | Tu email |
| `{{ settings.support_email }}` | El email de soporte del evento |
| `{{ settings.offline_payment_instructions }}` | Las instrucciones de [pago offline](/pagos/offline/) |
| `{{ settings.post_checkout_message }}` | El mensaje de confirmación del checkout |

### Del pedido

| Variable | Qué trae |
|---|---|
| `{{ order.number }}` | Número de orden |
| `{{ order.total }}` | Total pagado |
| `{{ order.date }}` | Fecha de la compra |
| `{{ order.first_name }}` · `{{ order.last_name }}` | Nombre del comprador |
| `{{ order.email }}` | Email del comprador |
| `{{ order.url }}` | Enlace a la orden |

### Del asistente y su entrada

| Variable | Qué trae |
|---|---|
| `{{ attendee.name }}` | Nombre del asistente |
| `{{ attendee.email }}` | Su email |
| `{{ attendee.seat }}` | Su butaca, si el evento tiene [asientos numerados](/evento/asientos/) |
| `{{ ticket.name }}` | Tipo de entrada |
| `{{ ticket.price }}` | Precio |
| `{{ ticket.url }}` | Enlace a la entrada con QR |

:::note
`{{ attendee.* }}` y `{{ ticket.* }}` solo tienen valor en la plantilla de **Entrada del asistente**: en la de confirmación de pedido todavía no hay un asistente puntual del que hablar.
:::

## Ajustes de email del evento

Dentro de cada evento, en **Configuración**, hay tres opciones que no dependen de las plantillas:

- **Correo electrónico de soporte**: la dirección a la que escribe el comprador si tiene un problema. Es la que aparece como contacto en los emails del evento.
- **Mensaje de pie de página**: un texto que se agrega al final de **todos** los emails de ese evento. Sirve para datos fiscales, la política de reembolsos o cómo llegar.
- **Notificar al organizador de nuevos pedidos**: si lo activás, te llega un email por cada compra.

:::tip
En eventos grandes, desactivá la notificación por pedido: con cientos de ventas te tapa la casilla y dejás de leer los emails que sí importan. Para seguir las ventas te sirve más el [panel del evento](/evento/panel/), que se actualiza en vivo.
:::

## Buenas prácticas

- Poné en el pie **cómo se ingresa** y **hasta cuándo se puede pedir un reembolso**: es lo que más te van a preguntar.
- No saques `{{ ticket.url }}` de la plantilla de la entrada: es el enlace que usa el asistente cuando no encuentra el email.
- Después de editar una plantilla, hacé **una compra de prueba** y leé el email en el celular. Es donde lo van a abrir.
