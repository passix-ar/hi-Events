# MercadoPago — Fallback de confirmación de pago (PENDIENTE)

> **Estado:** propuesta, no implementado.
> **Prioridad:** media (mejora de robustez, no es bug ni agujero de seguridad).
> **Fecha:** 2026-06-30

---

## El problema

Hoy, cuando alguien paga con MercadoPago, **la única vía por la que el backend se entera del
pago es el webhook** (`POST /webhooks/mercadopago`):

```
Comprador paga → MP manda webhook → backend marca orden pagada → entrega ticket
```

Si ese webhook **no llega** (MP caído un rato, server reiniciando, error de red, mala config),
el comprador **pagó pero se queda sin ticket** y nadie se entera automáticamente.

### Qué tan grave es

- **Bajo** en la práctica: MercadoPago **reintenta** los webhooks fallidos (con backoff, por
  horas), así que la mayoría de las fallas transitorias **se resuelven solas**.
- El escenario "pagó y nunca recibe" requiere que el webhook falle de forma **permanente**
  durante toda la ventana de reintentos de MP. Es raro, pero posible.

### Por qué Stripe no tiene este problema

El flujo de Stripe **ya tiene este fallback**: la página de retorno
(`frontend/src/components/routes/product-widget/PaymentReturn/index.tsx`) poletea la orden y,
si el webhook no llegó, consulta el estado del pago contra Stripe y completa la orden igual.
**MercadoPago no tiene ese equivalente** — depende 100% del webhook.

---

## La solución propuesta

Una **red de seguridad** para MercadoPago, espejo de lo que hace Stripe: cuando el comprador
vuelve de pagar, si el ticket todavía no apareció, la página le pregunta a MercadoPago
"¿este pago se aprobó?" y, si sí, entrega el ticket **sin depender del webhook**.

El webhook sigue siendo el camino principal; esto es solo el respaldo.

---

## Diseño (mínimo riesgo, aislado del flujo de Stripe)

### Backend

1. **Nuevo endpoint público** `POST /events/{event_id}/order/{order_short_id}/mercadopago/confirm`
   (con `throttle`, dentro del grupo de rutas públicas en `routes/api.php`).
   - Verifica la **sesión de checkout** (igual que crear preferencia, vía
     `CheckoutSessionManagementService`) → solo el comprador dueño de la orden puede llamarlo.
   - Si la orden ya está pagada → devuelve el estado y termina (idempotente, sin llamar a MP).
   - Si no: busca el pago en MP por **`external_reference` = short_id de la orden** (input
     derivado del server, **no** del cliente) usando el **access token del vendedor** (el de
     OAuth — ver nota de marketplace abajo).
   - Si encuentra uno `approved` → se lo pasa al **`PaymentApprovedHandler` existente**, que ya
     valida monto + moneda (`paymentMatchesOrder`), chequea el estado de la orden y es
     idempotente. **No se escribe lógica de cobro nueva: se reusa la probada.**
   - Devuelve el `payment_status` actual de la orden.

2. Cambiar los `back_urls` de éxito/pendiente en `CreateMercadoPagoPreferenceHandler` para que
   el comprador vuelva a la página de retorno (hoy van directo a `summary`).

### Frontend

3. **Componente nuevo `MercadoPagoReturn`** (aislado — **NO** tocar el `PaymentReturn` de
   Stripe, que es específico de Stripe y funciona): poletea la orden unos segundos; si no se
   completó, llama al endpoint de confirmación; al completarse redirige a `summary`; si falla,
   muestra mensaje claro ("pago en proceso / contactá soporte").
4. Ruta nueva `:orderShortId/mercadopago_return` en `src/router.tsx` + query/cliente +
   traducciones (Lingui).

---

## Datos confirmados en la documentación de MercadoPago

- **El `back_url` de retorno trae por GET:** `payment_id`, `status`, `external_reference`,
  `merchant_order_id`, `collection_id`, `collection_status`, `preference_id`, `payment_type`,
  `site_id`. Con `auto_return: "approved"` la redirección es automática (hasta ~40s).
- **Marketplace / split payments:** los pagos se leen con el **access token del vendedor**
  (el obtenido por OAuth, guardado cifrado en `account_mercadopago_platforms.access_token`),
  **no** con el token de la plataforma. El fallback debe usar el token del seller, igual que
  hace `MercadoPagoPreferenceService` al crear la preferencia.
- Buscar por `external_reference`:
  `GET https://api.mercadopago.com/v1/payments/search?external_reference={shortId}&sort=date_created&criteria=desc`
  con `Authorization: Bearer {sellerAccessToken}`.

Fuentes:
- https://www.mercadopago.com.ar/developers/en/docs/checkout-pro/configure-back-urls
- https://www.mercadopago.com.mx/developers/en/docs/split-payments/integration-configuration/integrate-marketplace

---

## Por qué este enfoque es seguro

- **No toca el flujo de Stripe** → riesgo cero ahí.
- **No confía en el `payment_id` del cliente:** busca por `external_reference` derivado del
  server (el short_id de la orden, que viene de una sesión de checkout verificada).
- **Reusa `PaymentApprovedHandler`** → mismas validaciones de seguridad que el webhook
  (monto, moneda, estado de orden, idempotencia).
- El único cambio al flujo vivo es el destino del `back_url` (de `summary` a
  `mercadopago_return`, que termina redirigiendo a `summary` igual).

---

## Archivos involucrados (estimado)

**Backend**
- `routes/api.php` — ruta nueva
- `app/Http/Actions/Orders/Payment/MercadoPago/ConfirmMercadoPagoPaymentActionPublic.php` (nuevo)
- `app/Services/Application/Handlers/Order/Payment/MercadoPago/ConfirmMercadoPagoPaymentHandler.php` (nuevo)
- `app/Services/.../MercadoPago/CreateMercadoPagoPreferenceHandler.php` — cambiar `back_urls`
- Tests unitarios del handler nuevo

**Frontend**
- `src/components/routes/product-widget/MercadoPagoReturn/index.tsx` (nuevo)
- `src/router.tsx` — ruta nueva
- `src/queries/` o `src/mutations/` — confirmación
- `src/api/order.client.ts` — método nuevo
- Traducciones (Lingui) para todos los idiomas

---

## Referencia: lo que YA se hizo en seguridad (contexto)

Esto quedó implementado en la revisión de seguridad de MercadoPago (no es parte del pendiente):

- **Webhook fail-closed en producción:** si falta `MP_WEBHOOK_SECRET`, el webhook **rechaza**
  en vez de aceptar sin firma (`IncomingWebhookHandler`).
- **Throttle:** `throttle:30,1` en crear preferencia y `throttle:600,1` en el webhook.
- **Vars MP documentadas** en `backend/.env.example` (`MP_WEBHOOK_SECRET` obligatorio en prod).
