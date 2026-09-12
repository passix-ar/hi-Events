---
title: Configuración del evento
description: Datos, fecha, ubicación, opciones de pago y ajustes generales de tu evento.
sidebar:
  order: 2
---

En **Configuración** definís todos los datos y el comportamiento de tu evento.

![Configuración del evento](/img/panel/ev-settings.png)

## Datos básicos

- **Nombre** del evento.
- **Fecha y hora** de inicio y fin.
- **Descripción** (aparece en la página pública).
- **Estado**: Borrador o Publicado.

## Ubicación

- **Presencial**: nombre del lugar y dirección (se muestra un mapa).
- **Online**: cargás el link de acceso, que se envía al comprador tras pagar.

## Ajustes generales

- **Moneda** del evento (por ejemplo ARS).
- **Zona horaria** (importante para fechas de venta y recordatorios).
- **Idioma** de los emails al comprador.

## Ajustes de pago

- Método(s) de pago habilitados: **MercadoPago** y/o **pago offline**.
- Ver [Cómo cobra Passix](/pagos/como-cobra-passix/) y [MercadoPago](/pagos/mercadopago/).

Un evento **publicado** tiene que quedarse siempre con al menos un método con el que cobrar. Si intentás destildarlos todos, no te va a dejar guardar: pasalo a **Borrador** primero.

Ojo con esto: tener MercadoPago tildado no alcanza si la cuenta de la organización no está conectada. En ese caso el evento se comporta como si no tuviera ese método, y para publicarlo vas a necesitar [conectar MercadoPago](/pagos/mercadopago/) o habilitar el [pago offline](/pagos/offline/).

## Ajustes del checkout

- **Tiempo de reserva**: cuántos minutos se le reservan las entradas a un comprador mientras paga. Por defecto son **15 minutos**. Pasado ese plazo, si no pagó, las entradas (y las [butacas](/evento/asientos/)) vuelven a estar disponibles para otros.
- **Términos y condiciones** del evento.
- **Mensaje de confirmación** que ve el comprador al terminar.

## SEO y compartir

Textos e imagen para cuando el link se comparte en redes o WhatsApp. La imagen que se usa es el flyer del evento → ver [Imágenes del evento](/evento/imagenes/).

## Correo electrónico y notificaciones

- **Correo electrónico de soporte**: la dirección de contacto que ve el comprador en los emails del evento.
- **Mensaje de pie de página**: un texto que se suma al final de todos los emails de este evento.
- **Notificar al organizador de nuevos pedidos**: te llega un email por cada compra.

Acá también podés escribir **plantillas propias** para los emails automáticos, que reemplazan a las de tu organización solo en este evento → ver [Emails automáticos y plantillas](/organizacion/emails/).

## Otras configuraciones

- **Modo de visualización de precios**: elegís cómo ve el comprador los [impuestos y cargos](/pagos/impuestos-cargos/).

| Opción | Qué ve el comprador |
|---|---|
| **Incluir impuestos y cargos en el precio** | Un solo precio final, con todo adentro. |
| **Mostrar impuestos y cargos por separado** | El precio de la entrada y, debajo, los cargos discriminados. |

- **Ocultar página de inicio**: saca del menú lateral la pantalla **Empezando** (la lista de pasos para preparar el evento). Útil cuando ya sabés el circuito y no querés el recordatorio.

## Zona de peligro

Al fondo de la configuración están las acciones que no se deshacen solas. **Solo los administradores de la cuenta** pueden usarlas: si no lo sos, vas a ver el aviso de que necesitás contactar al administrador.

### Archivar

**Archivar** saca el evento de la vista pública sin borrar nada: deja de ser visible y de venderse, pero conservás las órdenes, los asistentes y los reportes. Es reversible con **Restaurar evento**.

Es lo que querés en el 95% de los casos: un evento que ya pasó, o uno que se suspendió.

### Eliminar

**Eliminar** borra el evento y todos sus datos asociados, de forma permanente. Para confirmarlo tenés que **escribir una palabra de confirmación**.

:::caution
Un evento con **órdenes completadas no se puede eliminar**. Passix te lo va a impedir y te va a pedir que primero canceles o reembolses esas órdenes.

Esto es a propósito: esas órdenes son el respaldo de una venta real. Si lo que querés es sacar el evento de circulación, **archivalo** — no lo elimines.
:::

## Duplicar un evento

Si repetís una fecha, no arranques de cero: en el listado de eventos, desde el menú de la tarjeta del evento, elegí **Duplicar evento**.

Se abre un formulario donde ponés el **nombre y las fechas nuevas** y tildás qué querés copiar:

| Se puede copiar | |
|---|---|
| **Productos** | Tus entradas con precios y stock. |
| **Preguntas** | Las [preguntas del checkout](/evento/preguntas/). |
| **Ajustes** | La configuración del evento. |
| **Códigos promocionales** | Los [códigos](/evento/codigos-promocionales/). |
| **Asignaciones de capacidad** | Los topes de [aforo](/evento/capacidad/). |
| **Listas de registro** | Las [listas de check-in](/check-in/listas/). |
| **Imagen de portada del evento** | El flyer **y el banner destacado**. |
| **Logo del Ticket** | El logo del [diseño de entradas](/evento/diseno-entradas/). |
| **Webhooks** y **Afiliados** | Las integraciones y los [afiliados](/organizacion/afiliados/). |

El evento nuevo se crea en **Borrador**, con cero ventas y sus propias entradas y QR.

:::caution
Lo que **no** se copia: las **secciones de [asientos numerados](/evento/asientos/)**. Si tu evento vende butacas, tenés que volver a armar el plano en el evento duplicado.
:::

:::tip
Cargá bien la zona horaria y la moneda **antes** de vender: cambiarlas con ventas hechas puede confundir reportes.
:::
