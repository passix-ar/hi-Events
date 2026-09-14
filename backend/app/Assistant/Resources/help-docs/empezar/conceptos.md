---
title: Conceptos clave
description: El vocabulario de Passix — organización, evento, producto, orden, asistente y más.
---

Un glosario rápido para entender cómo se relacionan las piezas de Passix.

## Organización
La entidad bajo la que vendés (tu productora o marca). Contiene tus **eventos**, tu **cuenta de cobro** (MercadoPago) y tu **equipo**. Ver [Tu organización](/organizacion/panel/).

## Evento
Cada show, fiesta o función. Tiene fecha, lugar, su **página pública**, sus **entradas** y su propio panel de ventas.

## Producto / Entrada
Lo que vendés dentro de un evento. Puede ser una **entrada** (General, VIP) o un producto adicional. Cada uno tiene precio, stock y reglas. Ver [Entradas y productos](/evento/entradas/).

## Categoría de productos
Agrupa entradas relacionadas en la página (por ejemplo "Preventas", "Combos"). Ordena y organiza el checkout.

## Orden
Cada compra que hace una persona. Agrupa una o más entradas y tiene un **estado** (completada, esperando pago, cancelada) y un **estado de pago**. Ver [Órdenes](/ventas/ordenes/).

## Asistente (Attendee)
La persona que efectivamente ingresa con una entrada. Una orden de 3 entradas genera 3 asistentes, cada uno con su **QR**. Ver [Asistentes](/ventas/asistentes/).

## Sección de asientos
Una grilla de **filas × butacas** vinculada a una entrada, para eventos con asientos numerados. El comprador elige su butaca sobre un plano de la sala. Ver [Asientos numerados](/evento/asientos/).

## Check-in
El acto de validar la entrada de un asistente en la puerta escaneando su QR. Se organiza con **listas de check-in**. Ver [El día del evento](/check-in/listas/).

## Código promocional
Un código que aplica **descuento** o habilita entradas ocultas. Ver [Códigos promocionales](/evento/codigos-promocionales/).

## Estados de un evento
- **Borrador**: solo lo ves vos, no se vende.
- **Publicado**: visible y a la venta.
- Podés además marcarlo como **visible/oculto** en listados.

## ¿Cómo se relacionan?

```
Organización
└── Evento
    ├── Productos / Entradas  →  se compran en una...
    │   └── Sección de asientos (opcional: el comprador elige butaca)
    ├── Órdenes               →  cada una genera...
    │   └── Asistentes (QR)   →  se validan con...
    └── Listas de check-in
```
