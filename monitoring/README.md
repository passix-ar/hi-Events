# Monitoring — Passix

Stack de observabilidad self-hosted que corre en el mismo servidor que la app, con **docker compose
plano por SSH (NO Coolify)**. Se intentó migrarlo a un recurso Docker Compose de Coolify y falló:
Coolify convierte los bind-mounts de archivos de config en "persistent storage" vacío y los servicios
no encuentran su config. El deploy es manual (ver sección Deploy).

## Servicios

| Servicio | Imagen | Función |
|----------|--------|---------|
| Prometheus | `prom/prometheus:v2.51.0` | Recolecta métricas cada 15s, retención 30 días |
| Alertmanager | `prom/alertmanager:v0.27.0` | Enruta las alertas de Prometheus a Telegram |
| Grafana | `grafana/grafana:10.4.2` | Dashboards (expuesto en `grafana.getpassix.com` vía Traefik/Coolify) |
| Loki | `grafana/loki:2.9.4` | Almacena logs, retención 15 días |
| Promtail | `grafana/promtail:2.9.4` | Envía logs de todos los containers → Loki (label `app`) |
| node-exporter | `prom/node-exporter:v1.7.0` | CPU, RAM, disco, red del host |
| blackbox-exporter | `prom/blackbox-exporter:v0.24.0` | Health check HTTP de `app.getpassix.com` (front) y `api.getpassix.com` (backend) |

> **cAdvisor** (métricas por container) se removió: no soporta el storage driver `overlayfs` de Docker 29.x en este host. El estado de worker/scheduler se vigila por ausencia de logs en Loki (ver Alertas).

## Alertas configuradas

Métricas (`prometheus/alert-rules.yml`):

| Alerta | Condición | Severidad |
|--------|-----------|-----------|
| ServicioCaido | front o backend no responde HTTP 200 > 2 min | critical |
| DiscoAlto | Disco < 20% libre | warning |
| DiskoCritico | Disco < 10% libre | critical |
| RAMAlta | RAM > 90% por 5 min | warning |
| CPUAlta | CPU > 90% por 10 min | warning |

Logs (`loki/rules/fake/rules.yaml`, evaluadas por el ruler de Loki):

| Alerta | Condición | Severidad |
|--------|-----------|-----------|
| WorkerSinActividad | worker sin loguear nada por 10 min (caído o colgado) | critical |
| SchedulerSinActividad | scheduler sin loguear nada por 10 min | critical |

Todas se envían a **Alertmanager → Telegram**. El worker y el scheduler no tienen endpoint HTTP,
por eso se vigilan por **ausencia de logs** en Loki (Promtail los etiqueta con `app=worker` /
`app=scheduler` según el UUID de la app en Coolify — ver `promtail-config.yml`).

## Dashboards (provisionados, versionados en git)

Se cargan solos desde `grafana/provisioning/dashboards/`:

| Dashboard | UID | Qué muestra |
|-----------|-----|-------------|
| Passix — Overview | `passix-overview` | Sitio, CPU/RAM/disco del host |
| Passix — Uptime | `passix-uptime` | `probe_success`, latencia, vencimiento SSL (frontend y backend) |
| Passix — Logs por app | `passix-logs` | Explorador de logs (Loki) filtrado por `app` + búsqueda de texto |

Opcional, importar por ID desde la UI: **Node Exporter Full** (`1860`) para detalle fino del host.

---

## Deploy (manual por SSH — NO hay auto-deploy)

El stack vive en el server como clon de este repo en `/root/hi-events`, branch `develop`.
Tras pushear cambios que toquen `monitoring/`:

```bash
ssh -i ~/.ssh/passix_prod root@5.78.43.237
cd /root/hi-events && git pull origin develop
cd monitoring && docker compose -f docker-compose.monitoring.yml up -d
# Los servicios cuya config es bind-mount NO se recrean solos si solo cambió el archivo:
docker restart passix_promtail passix_prometheus passix_grafana   # según qué configs cambiaron
```

Loki solo se recrea solo si cambió el compose (mounts/imagen); si solo cambió
`loki-config.yml` o `loki/rules/`, agregar `passix_loki` al restart.

### Variables de entorno (en `/root/hi-events/monitoring/.env`, no en git)
- `GRAFANA_PASSWORD` — contraseña del admin de Grafana
- `GRAFANA_DOMAIN` — `grafana.getpassix.com`
- `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID` — inyectadas al template de Alertmanager al arrancar

### Volúmenes
Declarados `external: true` (`passix_prometheus_data`, `passix_grafana_data`, `passix_loki_data`) para
preservar el histórico entre redeploys. **No borrar estos volúmenes.**

---

## Configurar alertas por Telegram

### Paso 1 — Crear el bot
1. Abrir `@BotFather` en Telegram → `/newbot` → guardar el **Bot Token** (`123456789:AAAA...`).

### Paso 2 — Obtener el Chat ID
1. Escribirle un mensaje al bot (o agregarlo a un grupo y mandar un mensaje).
2. Abrir `https://api.telegram.org/bot<TOKEN>/getUpdates` y copiar el `id` del objeto `chat` (negativo para grupos).

### Paso 3 — Cargar en el server
1. Agregar `TELEGRAM_BOT_TOKEN` y `TELEGRAM_CHAT_ID` a `/root/hi-events/monitoring/.env`.
2. `docker compose -f docker-compose.monitoring.yml up -d alertmanager` (el entrypoint inyecta
   los valores en `alertmanager/alertmanager.tmpl.yml` con sed al arrancar).

### Paso 4 — Probar
`docker exec passix_alertmanager amtool alert add test summary="prueba" --alertmanager.url=http://localhost:9093`
→ debe llegar el mensaje a Telegram.

## Monitoreo externo complementario (recomendado)

Si el servidor entero cae, Grafana también cae y no puede alertar. **UptimeRobot** (gratis) o **Uptime
Kuma** hacen el health check desde afuera. Configurar contra `https://app.getpassix.com`.

---

## Estructura de archivos

```
monitoring/
├── docker-compose.monitoring.yml   ← recurso Docker Compose de Coolify
├── .env.example
├── prometheus/
│   ├── prometheus.yml       ← scrape targets + alerting → alertmanager
│   ├── alert-rules.yml      ← reglas de alertas
│   └── blackbox.yml         ← módulos HTTP
├── alertmanager/
│   └── alertmanager.yml.example  ← plantilla Telegram (el real va en Coolify)
├── loki/
│   ├── loki-config.yml
│   └── rules/fake/rules.yaml    ← alertas LogQL del ruler (worker/scheduler sin actividad)
├── promtail/
│   └── promtail-config.yml  ← recolector Docker + label app
└── grafana/
    └── provisioning/
        ├── datasources/
        │   └── datasources.yml   ← Prometheus + Loki
        └── dashboards/
            ├── dashboards.yml        ← provider
            ├── passix-overview.json
            ├── passix-uptime.json
            └── passix-logs.json
```
