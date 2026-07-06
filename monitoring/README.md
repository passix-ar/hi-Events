# Monitoring — Passix

Stack de observabilidad self-hosted que corre en el mismo servidor que la app, **gestionado por
Coolify** (recurso Docker Compose conectado a este repo). Un push a `develop` que toque `monitoring/`
redeploya el stack automáticamente — no más `docker compose up -d` a mano por SSH.

## Servicios

| Servicio | Imagen | Función |
|----------|--------|---------|
| Prometheus | `prom/prometheus:v2.51.0` | Recolecta métricas cada 15s, retención 30 días |
| Alertmanager | `prom/alertmanager:v0.27.0` | Enruta las alertas de Prometheus a Telegram |
| Grafana | `grafana/grafana:10.4.2` | Dashboards (expuesto en `grafana.getpassix.com` vía Traefik/Coolify) |
| Loki | `grafana/loki:2.9.4` | Almacena logs, retención 15 días |
| Promtail | `grafana/promtail:2.9.4` | Envía logs de todos los containers → Loki (label `app`) |
| node-exporter | `prom/node-exporter:v1.7.0` | CPU, RAM, disco, red del host |
| cAdvisor | `gcr.io/cadvisor/cadvisor:v0.49.1` | Métricas por container (CPU, RAM, reinicios) |
| blackbox-exporter | `prom/blackbox-exporter:v0.24.0` | Health check HTTP de `app.getpassix.com` |

## Alertas configuradas (`prometheus/alert-rules.yml`)

| Alerta | Condición | Severidad |
|--------|-----------|-----------|
| SitioCalido | HTTP no responde > 2 min | critical |
| DiscoAlto | Disco < 20% libre | warning |
| DiskoCritico | Disco < 10% libre | critical |
| RAMAlta | RAM > 90% por 5 min | warning |
| CPUAlta | CPU > 90% por 10 min | warning |
| ContainerReiniciado | container sin actividad (requiere cAdvisor) | warning |

Todas se envían a **Alertmanager → Telegram**.

## Dashboards (provisionados, versionados en git)

Se cargan solos desde `grafana/provisioning/dashboards/`:

| Dashboard | UID | Qué muestra |
|-----------|-----|-------------|
| Passix — Overview | `passix-overview` | Sitio, CPU/RAM/disco, CPU/mem por container |
| Passix — Uptime | `passix-uptime` | `probe_success`, latencia, vencimiento SSL |
| Passix — Logs por app | `passix-logs` | Explorador de logs (Loki) filtrado por `app` |

Opcional, importar por ID desde la UI: **Node Exporter Full** (`1860`) para detalle fino del host.

---

## Deploy (gestionado por Coolify)

El stack es un recurso **Docker Compose** en Coolify apuntando a:
- Repo: `passix-ar/hi-events` · Branch: `develop`
- Compose path: `monitoring/docker-compose.monitoring.yml`

### Variables de entorno (en Coolify, no en git)
- `GRAFANA_PASSWORD` — contraseña del admin de Grafana
- `GRAFANA_DOMAIN` — `grafana.getpassix.com`

### File mount (secreto, en Coolify, no en git)
- Destino: `/etc/alertmanager/alertmanager.yml`
- Contenido: copiar `alertmanager/alertmanager.yml.example` y reemplazar `__BOT_TOKEN__` y `__CHAT_ID__`.

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

### Paso 3 — Cargar en Coolify
1. En el recurso de monitoring → **Storages → Add File Mount**.
2. Path: `/etc/alertmanager/alertmanager.yml`.
3. Contenido: el `.example` con el token y chat id reales.
4. Redeploy.

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
│   └── loki-config.yml
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
