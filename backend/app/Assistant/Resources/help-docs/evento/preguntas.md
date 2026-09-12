---
title: Preguntas del checkout
description: Pedí datos extra al comprador o a cada asistente durante la compra.
sidebar:
  order: 9
---

Con **Preguntas** pedís información adicional durante la compra, además de nombre y email.

![Preguntas de registro del checkout](/img/panel/ev-questions.png)

## Tipos de pregunta

- **Por orden**: se pregunta una vez por compra (ej. "¿Cómo nos conociste?").
- **Por asistente**: se pregunta por cada entrada (ej. nombre, DNI, talle de remera).

:::note
Que se pidan datos de **cada** asistente o solo los del comprador depende de la configuración de recopilación de datos, que arranca con lo que definiste en los [valores predeterminados de tu organización](/organizacion/panel/). Si está en **por pedido**, las preguntas por asistente pierden sentido: todas las entradas quedan con los datos de quien compró.
:::

## Formatos de respuesta

- Texto corto / largo
- Opción única (dropdown / radio)
- Opción múltiple (checkboxes)
- Fecha
- Dirección

## Crear una pregunta

1. En el evento, andá a **Preguntas de registro** (menú **Configuración y diseño**).
2. **Crear pregunta**.
3. Definí el **título**, el **tipo** (por orden o por asistente), el **formato** y si es **obligatoria**.
4. Ordenala arrastrando en la lista.

## Dónde ves las respuestas

- En el detalle de cada [orden](/ventas/ordenes/) y [asistente](/ventas/asistentes/).
- En los [reportes](/ventas/reportes/) exportables (CSV), una columna por pregunta.

:::caution
Pedí solo lo necesario: cada campo obligatorio agrega fricción al checkout y puede bajar la conversión.
:::
