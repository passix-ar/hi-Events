---
title: Órdenes
description: "Consultá, filtrá y gestioná cada compra: estados, pagos, reenvíos y cancelaciones."
sidebar:
  order: 1
---

Una **orden** es cada compra que hace una persona. Agrupa una o más entradas y guarda el estado del pago, los datos del comprador y las respuestas del checkout.

![Listado de pedidos/órdenes](/img/panel/ev-orders.png)

## Ver las órdenes

En el evento, andá a **Pedidos** (menú **Venta de boletos**). Ves la lista con: comprador, total, estado, medio de pago y fecha. Podés **buscar** por nombre/email y **filtrar** por estado.

## Estados

| Estado de la orden | Significado |
|---|---|
| **Completada** | Pago confirmado, entradas emitidas. |
| **Esperando pago** | Falta confirmar el pago (offline o MP pendiente). |
| **Cancelada** | Anulada; el stock se libera. |

| Estado de pago | Significado |
|---|---|
| **Pagado** | Entró el dinero. |
| **Pendiente** | Aún no confirmado. |
| **Reembolsado** | Se devolvió (total o parcial). |

## Acciones sobre una orden

Abriendo una orden podés:

- **Reenviar** el email con las entradas.
- **Marcar como pagada** (para [pago offline](/pagos/offline/)).
- **Cancelar** la orden y liberar el stock.
- **Reembolsar** total o parcial → ver [Reembolsos](/ventas/reembolsos/).
- Ver las **respuestas** a las [preguntas del checkout](/evento/preguntas/).
- Editar datos del comprador o de los asistentes.

## Exportar

Podés **exportar** las órdenes a CSV desde [Reportes](/ventas/reportes/) para tu contabilidad.

:::tip
Si un comprador dice que "no le llegó la entrada", lo más rápido es abrir su orden y usar **Reenviar email** (pedile que revise spam).
:::
