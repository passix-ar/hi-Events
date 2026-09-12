# MercadoPago — Renovación automática de tokens OAuth

Los `access_token` que MercadoPago entrega al conectar a un organizador duran **180 días**.
Pasado ese plazo el organizador deja de poder cobrar. Este documento describe cómo Passix los
renueva solos, qué se ve cuando algo falla y qué hacer en cada caso.

## Cómo funciona

- Al conectar, el callback de OAuth guarda `access_token`, `refresh_token` y `token_expires_at`
  en `account_mercadopago_platforms` (tokens encriptados).
- **Una conexión sin `refresh_token` se rechaza** a propósito: sin él no hay forma de renovar y la
  cuenta moriría a los 180 días sin que nadie mire. Si pasa, el log del backend dice
  `MercadoPago OAuth returned an incomplete token pair — offline_access missing on the app?` y
  hay que revisar que la aplicación en el panel de desarrolladores de MercadoPago tenga el scope
  `offline_access`.
- Si MercadoPago no manda `expires_in`, se asume 180 días. Ninguna fila queda sin vencimiento.
- El scheduler corre `mercadopago:refresh-tokens` **todos los días a las 05:00 UTC** (02:00 en
  Argentina). Toma las conexiones cuyo token vence en los próximos 30 días y todavía no venció,
  pide el par nuevo a MercadoPago y lo persiste de inmediato (el refresh token rota en cada
  renovación). Cada fila se procesa con la fila lockeada para que dos corridas no se pisen.
- Nada de esto requiere acción del organizador. Las cuentas que ya estaban conectadas antes del
  deploy entran solas cuando llegan a la ventana de 30 días.

## Comandos

```bash
# Ver qué renovaría hoy, sin llamar a MercadoPago
php artisan mercadopago:refresh-tokens --dry-run

# Renovar una sola cuenta, ignorando la ventana (recuperación manual / prueba controlada)
php artisan mercadopago:refresh-tokens --account=<account_id>

# Verificar contra MercadoPago que el token guardado sirve (solo lectura, no consume nada)
php artisan mercadopago:check-token
php artisan mercadopago:check-token --account=<account_id>
```

Se corren dentro del contenedor del **backend** o del **scheduler** (los dos tienen el código).

## Alertas (Telegram, vía Loki → Alertmanager)

| Alerta | Cuándo dispara | Qué hacer |
|---|---|---|
| 🟠 `MercadoPagoRefreshFallido` | Alguna renovación falló en las últimas 24h (se repite 1 vez por día mientras siga fallando) | Buscar en Grafana `app=scheduler` + `MercadoPago token refresh`. Ver la tabla de abajo según el mensaje. |
| 🔴 `MercadoPagoRefreshNoCorrio` | El comando no aparece en los logs del scheduler en 26h | El scheduler no lo está ejecutando: revisar que el contenedor esté vivo (`SchedulerSinActividad` suele disparar junto), y correr a mano `--dry-run`. |

Mensajes de error del comando y su significado:

| Mensaje en el log | Causa | Acción |
|---|---|---|
| `grant is dead, organizer must re-authorize` (`invalid_grant`) | El organizador desvinculó la app desde su cuenta de MercadoPago, o la cadena de refresh se quemó | Avisarle al organizador que reconecte MercadoPago desde su panel (Cuenta → Pagos). Mientras tanto sigue cobrando hasta `token_expires_at`; al vencer, el checkout deja de ofrecer MercadoPago solo. |
| `platform credentials invalid` (`unauthorized_client` / `invalid_client`) | `MP_CLIENT_ID` / `MP_CLIENT_SECRET` mal configurados en el scheduler | La corrida se corta entera (afecta a todos igual). Corregir las env vars en Coolify, redeployar el scheduler y correr `--dry-run` para confirmar. |
| `rate limited by MercadoPago` (429) | Límite de MercadoPago | Nada: la corrida del día siguiente reintenta. |
| `no refresh token stored` | Fila vieja sin refresh token | El organizador tiene que reconectar. No debería pasar con conexiones nuevas (se rechazan al conectar). |
| `incomplete response` / `non-JSON body` | MercadoPago respondió algo raro | Reintenta al día siguiente. Si persiste, mirar el status de MercadoPago. |

Las alertas se apoyan en la salida de `schedule:run` (`Running [...] DONE|FAIL`) y en los
`Log::error()` del comando. **El scheduler corre con `LOG_LEVEL=error`**: las líneas `info` del
comando no llegan a Loki en producción.

## Qué NO hace (a propósito)

- No marca cuentas como "revocadas" ni las esconde del checkout antes de que venza el token: el
  panel del organizador no muestra ese estado, así que sería un apagón silencioso. Un grant muerto
  se loguea todos los días hasta que venza o el organizador reconecte.
- No detecta un grant muerto **antes** de la ventana de 30 días. Si el organizador desvincula la
  app el día 10, nos enteramos el día 150. Está anotado como mejora aparte.

## Verificación después del deploy

1. `php artisan mercadopago:refresh-tokens --dry-run` → lista vacía si nada vence en 30 días.
2. `php artisan mercadopago:check-token` → todas las cuentas OK.
3. Sobre una cuenta **propia**: `refresh-tokens --account=N` y después `check-token --account=N`.
   Es la primera vez que el endpoint de refresh corre de verdad; si falla, no tocó a nadie más.
4. Al día siguiente, en Grafana Explore: `{app="scheduler"} |= "mercadopago:refresh-tokens"`
   tiene que mostrar la línea `Running [...] DONE` de las 05:00 UTC.
