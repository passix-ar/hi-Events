---
title: Seguimiento y analítica (píxeles)
description: Conectá Meta Pixel, Google Analytics, GTM o TikTok a tus páginas públicas, y qué responsabilidad asumís al hacerlo.
sidebar:
  order: 6
---

**Seguimiento y analítica** te deja conectar píxeles de medición a tus **páginas públicas**: la de cada evento y la de tu organización. Sirve para medir campañas, armar públicos y ver de dónde vienen tus ventas.

Lo encontrás en la **configuración de la organización**, y aplica a **todos** tus eventos.

:::caution[Leelo antes de activarlo]
Activar un píxel no es solo pegar un número: pasás a ser **corresponsable de los datos** que se recolectan, junto con la plataforma. Passix te lo hace declarar de forma explícita antes de guardar. Más abajo está el detalle.
:::

## Qué podés conectar

| Proveedor | Qué se carga | Formato |
|---|---|---|
| **Facebook Pixel** (Meta) | ID del píxel | Numérico, ej. `1234567890` |
| **Google Analytics 4** | Measurement ID | `G-XXXXXXXXXX` |
| **Google Tag Manager** | Container ID | `GTM-XXXXXXX` |
| **TikTok Pixel** | ID del píxel | `CXXXXXXXXXX` |

Cada uno tiene su **interruptor** propio: podés dejar el ID cargado y apagar el píxel sin borrarlo.

## Cómo conectarlo

1. Andá a la **configuración de tu organización** → **Seguimiento y analítica**.
2. Activá el interruptor del proveedor que quieras usar.
3. Pegá el **ID** en el formato que te pide.
4. Tildá **"Reconozco mis responsabilidades como responsable del tratamiento de datos"**.
5. **Guardar**.

Sin ese tilde **no te deja guardar**. No es un trámite: es la declaración de que entendés lo que sigue.

## El banner de cookies

Apenas hay un píxel activo, tus páginas públicas le muestran al visitante un **banner de consentimiento**:

> *Utilizamos cookies para ayudarnos a entender cómo se usa el sitio y mejorar su experiencia.* — con los botones **Aceptar** y **Rechazar**, y un enlace a la **política de privacidad**.

El banner aparece solo cuando el seguimiento está activo. Si no tenés ningún píxel, tus visitantes no lo ven.

## Tu responsabilidad

Este es el punto importante y conviene que quede escrito:

- Al conectar un píxel, **vos y la plataforma son corresponsables** de los datos que se recolectan.
- Sos vos quien tiene que asegurarse de contar con una **base legal** para ese tratamiento, según la normativa de privacidad que te aplique.
- Eso incluye tener una **política de privacidad propia** accesible y coherente con lo que realmente hacés con los datos.

Si no estás seguro de tener esa base legal cubierta, **la opción prudente es no activar los píxeles**. Medir mejor no compensa un problema de datos personales, y desactivarlos después no borra lo ya recolectado por el proveedor.

:::note
Los datos que recolecta cada píxel quedan en poder del proveedor (Meta, Google, TikTok), bajo **sus** términos. Passix no los almacena ni te los devuelve: lo que se mide se consulta desde el panel de cada proveedor.
:::

## Qué conviene medir

- **Visitas a la página del evento**, para saber cuánta gente llega.
- **Inicio de checkout** vs **compra concretada**, que es donde se ve la fricción real.
- El **origen** del tráfico, para saber qué canal te trae ventas y cuál solo ruido.

Si tu objetivo es simplemente saber **qué canal vende**, mirá primero los [códigos promocionales](/evento/codigos-promocionales/) por canal y los [afiliados](/organizacion/afiliados/): resuelven la pregunta sin sumar datos personales de terceros ni banner de cookies.

## Buenas prácticas

- Un proveedor a la vez. Cuatro píxeles prendidos "por las dudas" multiplican tu exposición sin darte mejores respuestas.
- Si usás **Google Tag Manager**, cargá el resto **desde adentro de GTM** en lugar de duplicarlos acá.
- Probá que el píxel dispara antes de gastar en pauta: la mayoría de los proveedores tiene su propio extensor de diagnóstico.
- Revisá una vez por año si seguís usando lo que conectaste. Un píxel olvidado sigue midiendo.
