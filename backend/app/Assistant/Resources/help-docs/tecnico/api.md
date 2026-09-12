---
title: API pública
description: Consultá eventos, organizadores y productos de forma programática con la API pública de Passix.
sidebar:
  order: 1
---

Passix expone una **API REST pública** para leer datos de eventos, organizadores y productos, y para integrarlos en tu propio sitio.

## Endpoint base

```
https://api.getpassix.com
```

## Endpoints públicos (sin autenticación)

Los datos públicos no requieren token:

| Método | Endpoint | Devuelve |
|---|---|---|
| `GET` | `/public/events` | Eventos públicos. |
| `GET` | `/public/events/{event_id}` | Detalle de un evento. |
| `GET` | `/public/events/{event_id}/products` | Entradas/productos de un evento. |
| `GET` | `/public/organizers/{organizer_id}` | Datos de un organizador. |
| `GET` | `/public/organizers/{organizer_id}/events` | Eventos de un organizador. |
| `GET` | `/public/events/{event_id}/promo-codes/{codigo}` | Valida un código promocional. |

### Ejemplo

```bash
curl https://api.getpassix.com/public/events
```

Respuesta (resumida):

```json
{
  "data": [
    {
      "id": 123,
      "title": "Mi Evento",
      "start_date": "2026-08-15T21:00:00-03:00",
      "status": "PUBLISHED"
    }
  ]
}
```

## Casos de uso

- Mostrar tus eventos en **tu propio sitio** o app.
- Alimentar una cartelera o listado con tus próximos shows.
- Verificar códigos promocionales desde un sistema externo.

Para **reaccionar en tiempo real** a ventas o check-ins (en vez de consultar la API en loop), usá [Webhooks](/tecnico/webhooks/).

:::note
Los endpoints públicos son de **solo lectura** sobre datos públicos. Para operaciones privadas o integraciones a medida, escribinos desde [getpassix.com](https://getpassix.com).
:::
