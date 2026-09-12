---
title: Widget embebible
description: Insertá el checkout de Passix en tu propia web sin salir de tu sitio.
sidebar:
  order: 3
---

El **widget** te deja mostrar el flujo de compra de Passix embebido en tu web, en lugar de mandar al comprador a una página separada.

![Widget embebible del evento](/img/panel/ev-widget.png)

## Qué resuelve

- Mantener al comprador dentro de tu sitio.
- Reducir fricción en la compra.
- Reutilizar el checkout y el pago de Passix con tu branding.

## Flujo del widget

1. El comprador elige las entradas.
2. Carga sus datos.
3. Paga con el método disponible.
4. Vuelve al sitio con el estado final de la orden.

## Estados comunes

| Estado | Qué pasa |
|---|---|
| **Selección de productos** | El comprador elige entradas. |
| **Carga de información** | Completa nombre, email y preguntas. |
| **Pago** | Se procesa MercadoPago o pago offline. |
| **Retorno** | Se muestra confirmación o error. |
| **Impresión** | Opcionalmente se abre la vista para imprimir orden o entradas. |

## Recomendaciones de integración

- Probalo primero en un entorno de desarrollo.
- Escuchá el resultado final por **webhook** además de la respuesta visual del widget.
- Mostrá un mensaje claro si la compra queda en estado pendiente.

## Estilos y CSS

El widget carga sus propios estilos. Para retoques menores podés usar CSS global en tu página.

:::tip
Si integrás el widget, combiná esta guía con [API pública](/tecnico/api/) y [Webhooks](/tecnico/webhooks/) para tener el flujo completo.
:::
