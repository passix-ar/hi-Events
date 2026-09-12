---
title: Asientos numerados (butacas)
description: Armá el plano de tu sala para que cada comprador elija su butaca, con secciones, pasillos y escenario.
sidebar:
  order: 4
---

Con **Asientos numerados** el comprador no compra "una entrada": elige **su butaca** en un plano de la sala. Sirve para teatros, auditorios, cenas con mesas o cualquier lugar donde la ubicación importa.

Lo encontrás dentro del evento, en **Asientos numerados** (menú **Gestión de invitados**).

## Cómo funciona

Todo gira alrededor de la **sección de asientos**: una grilla de filas × asientos **vinculada a una entrada**.

```
Entrada "Platea"        →  Sección "Platea"      (10 filas × 20 asientos)
Entrada "Sector VIP"    →  Sección "Sector VIP"  (5 filas × 12 asientos)
Entrada "General"       →  (sin sección)         →  se vende como siempre
```

Tres consecuencias que conviene tener claras desde el arranque:

- La butaca **se elige por entrada**. Si el comprador lleva 2 Plateas, elige 2 butacas de la sección Platea.
- Una entrada **sin sección activa** se sigue vendiendo como entrada general, sin plano. Podés mezclar los dos modos en el mismo evento.
- El plano lo armás vos: cada sección es un bloque que arrastrás, más el **escenario** como punto de referencia.

:::note
Antes de crear tu primera sección necesitás al menos una **entrada** creada (de tipo entrada, no un producto adicional). Si no tenés ninguna, Passix te lo avisa y te ofrece crearla. Ver [Entradas y productos](/evento/entradas/).
:::

## Crear una sección de asientos

1. En el evento, andá a **Asientos numerados**.
2. **Crear sección de asientos**.
3. Completá:

| Campo | Qué es |
|---|---|
| **Nombre de la sección** | Cómo la ve el comprador en el plano (ej. *Platea*, *Pullman*, *Sector VIP*). |
| **¿Para qué producto vende asientos esta sección?** | La entrada con la que se compran esas butacas. Solo aparecen productos de tipo entrada. |
| **Cantidad de filas** | Hasta 100. |
| **Asientos por fila** | Hasta 100. |
| **Estado** | **Activo** (los compradores pueden elegir butacas) o **Inactivo** (la sección queda oculta). |
| **Pasillos** | Después de qué número de asiento se dibuja un pasillo. |
| **Forma del lugar** | Clic sobre un asiento para **bloquearlo**. |

4. Guardá.

Una sección no puede superar los **2.000 asientos** (filas × asientos por fila) y tiene que quedar con **al menos un asiento disponible**.

### Cómo se nombran las butacas

Las filas se etiquetan solas: **A, B, C…** y siguen con **AA, AB** si pasás de 26. Los asientos se numeran **1, 2, 3…** dentro de cada fila.

La butaca del asistente termina guardada como **`Nombre de la sección - Fila+Asiento`**, por ejemplo `Sector VIP - A5`. Si después renombrás la sección, Passix **actualiza la etiqueta de las butacas ya vendidas** para que no queden con el nombre viejo.

### Pasillos y asientos bloqueados

Son las dos herramientas para que el plano se parezca a la sala real:

- **Pasillos**: elegís los números de asiento después de los cuales queda un hueco. Con 12 asientos por fila y pasillos en `3` y `9`, la fila se dibuja `1-2-3 | 4…9 | 10-11-12`.
- **Asientos bloqueados**: en **Forma del lugar** hacés clic en cada asiento que **no existe o no se vende** (una columna, la posición de una silla de ruedas, un hueco de la sala). Los bloqueados **no se venden** y aparecen como espacios vacíos en el plano.

:::tip
El bloqueo es la forma de armar salas que no son rectángulos perfectos. Definí la grilla más grande que abarque el sector y después bloqueá todo lo que sobra.
:::

## Armar el plano de la sala

Debajo de las tarjetas de secciones está el **plano**: ahí se ve el escenario y cada sección como un bloque.

- **Arrastrá** el escenario y cada sección hasta que el plano se parezca a la sala.
- **Ordenar** acomoda todo en una grilla prolija si quedó desparramado.
- El **Escenario** es solo una referencia visual para el comprador: no vende butacas. Podés **Eliminarlo** del plano y **Volver a ponerlo** cuando quieras.

Este plano es **el mismo que ve el comprador**: lo que acomodás acá es lo que se dibuja en el checkout.

## Estados de una butaca

La tarjeta de cada sección lleva el conteo en vivo — **disponibles / reservados / vendidos** — y en el plano cada butaca tiene su color:

| Estado | Qué significa |
|---|---|
| **Disponible** | Se puede elegir y comprar. |
| **Reservado** | Alguien la tiene tomada mientras completa el pago. Se libera sola si no paga. |
| **Vendido** | Ya tiene dueño: orden pagada, o esperando el pago offline. |

Los asientos **bloqueados** no aparecen en esta cuenta: no son un estado, directamente no existen en el plano (se dibujan como huecos).

El tiempo que una butaca queda **reservada** es el **Tiempo de reserva** del evento (15 minutos por defecto). Se configura en [Configuración del evento](/evento/configuracion/).

## El stock de la entrada sigue mandando

Este es el punto que más confunde: **la sección y el stock de la entrada son dos límites distintos** y se aplican los dos.

Si tu sección Platea tiene 200 butacas pero la entrada Platea tiene stock 150, vas a vender **150**: cuando se agote la entrada, el resto del plano queda sin vender aunque haya butacas libres.

:::caution
Poné el **stock de la entrada igual a la cantidad de butacas vendibles** de su sección (filas × asientos, menos los bloqueados). Es la única forma de que el plano y el stock se agoten juntos.
:::

Lo mismo vale para la [capacidad y aforo](/evento/capacidad/): si la entrada participa de una asignación de capacidad, el tope compartido también puede cerrar la venta antes de que se llene el plano.

## Qué ve el comprador

En la página del evento, apenas elige la cantidad de una entrada con sección activa, se le abre **Elige tus asientos** con el plano:

1. Ve el escenario y las secciones tal como las acomodaste.
2. Toca las butacas libres hasta completar la cantidad que eligió (*"2 de 3 butacas seleccionadas"*).
3. No puede avanzar al pago hasta elegir **todas** las butacas de esa entrada.
4. Las butacas quedan **reservadas** mientras paga.

Si en el medio otra persona compra una de sus butacas, al confirmar le aparece *"Uno o más de los asientos que seleccionaste ya no están disponibles"* y tiene que elegir otras.

## Dónde aparece la butaca después de la venta

Una vez comprada, la butaca acompaña al asistente en todos lados:

- En su **ticket con QR** y en el email de confirmación (*"Tu butaca: Sector VIP - A5"*).
- En la columna **Asiento** de [Asistentes](/ventas/asistentes/) y en el **CSV** exportado.
- En la pantalla de **check-in**, junto al nombre, para poder ubicar a la persona en la puerta.
- Como variable **`{{ attendee.seat }}`** si usás plantillas de email.

## Editar una sección que ya vendió

Podés editar una sección en cualquier momento, pero Passix protege lo ya vendido. **Mientras haya butacas reservadas o vendidas** no vas a poder:

- **Cambiar la entrada** a la que está vinculada la sección.
- **Achicar** la grilla si la fila o el asiento que desaparece está ocupado (*"La sección no se puede achicar: el asiento B7 está reservado o vendido"*).
- **Bloquear** una butaca ocupada (*"El asiento A3 no se puede bloquear porque está reservado o vendido"*).
- **Eliminar** la sección (*"Esta sección no se puede eliminar mientras haya asientos reservados o vendidos"*).

**Agrandar** la sección (sumar filas o asientos por fila) sí se puede siempre: las butacas nuevas se generan libres y las existentes no se tocan.

### Sacar una sección de la venta

Si querés frenar la venta de un sector sin borrar nada, **Desactivá** la sección: desaparece del plano del comprador y esa entrada vuelve a venderse sin elección de butaca. Las butacas ya vendidas siguen siendo válidas.

## Cancelaciones y reembolsos

- Si **cancelás una orden**, sus butacas vuelven al plano como disponibles.
- Si **cancelás un asistente** puntual, se libera su butaca.

En los dos casos la butaca queda lista para venderse de nuevo, sin que tengas que tocar el plano.

## Al duplicar un evento

Las secciones de asientos **no se copian**. Si [duplicás un evento](/evento/configuracion/) que vendía butacas, el evento nuevo llega con sus entradas pero sin plano: tenés que volver a crear las secciones.

Es rápido si anotás la receta de cada sector (nombre, entrada vinculada, filas × asientos, pasillos y asientos bloqueados) la primera vez que armás la sala.

## Buenas prácticas

- Armá el plano **antes de publicar** el evento: mover secciones con ventas hechas es incómodo y cambia lo que ya vio el comprador.
- Un **nombre por sector real** (*Platea baja*, *Pullman*, *Palco 3*), no nombres genéricos: es lo que el asistente va a leer en su ticket y buscar en la sala.
- Probá el plano desde el **celular** con la [vista previa del evento](/evento/pagina/): la mayoría de tus compradores elige butaca en pantalla chica.
- Si tu sala tiene sectores con precios distintos, **una entrada y una sección por sector**. No intentes resolverlo con una sola sección.
