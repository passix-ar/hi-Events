# Monitoring — Passix

Stack de observabilidad self-hosted que corre en el mismo servidor que la app. Diseñado para ser liviano (~380MB RAM) y fácil de operar.

## Servicios

| Servicio | Imagen | Función |
|----------|--------|---------|
| Prometheus | `prom/prometheus:v2.51.0` | Recolecta métricas cada 15s, retención 30 días |
| Grafana | `grafana/grafana:10.4.2` | Dashboards + alertas |
| Loki | `grafana/loki:2.9.4` | Almacena logs, retención 15 días |
| Promtail | `grafana/promtail:2.9.4` | Envía logs de todos los containers → Loki |
| node-exporter | `prom/node-exporter:v1.7.0` | CPU, RAM, disco, red del host |
| blackbox-exporter | `prom/blackbox-exporter:v0.24.0` | Health check HTTP del sitio |

## Métricas monitoreadas

- **Host**: CPU, RAM, disco, tráfico de red, load average
- **Uptime**: HTTP 200 del dominio público cada 15s
- **Logs**: todos los containers (backend, frontend, nginx, worker, queue)

## Alertas configuradas

| Alerta | Condición | Severidad |
|--------|-----------|-----------|
| SitioCalido | HTTP no responde > 2 min | critical |
| DiscoAlto | Disco < 20% libre | warning |
| DiskoCritico | Disco < 10% libre | critical |
| RAMAlta | RAM > 90% por 5 min | warning |
| CPUAlta | CPU > 90% por 10 min | warning |

---

## Deploy

### 1. Configurar variables

```bash
cd hi-events/monitoring
cp .env.example .env
nano .env   # completar GRAFANA_PASSWORD y APP_URL
```

### 2. Levantar el stack

```bash
docker compose -f docker-compose.monitoring.yml --env-file .env up -d
```

### 3. Verificar que todo esté corriendo

```bash
docker compose -f docker-compose.monitoring.yml ps
```

Todos los servicios deben estar en estado `Up`.

### 4. Exponer Grafana vía Coolify

En Coolify, agregar un nuevo servicio apuntando al puerto `3001` con el dominio `grafana.passix.com.ar`. Coolify gestiona el SSL automáticamente.

### 5. Primer login

- URL: `https://grafana.passix.com.ar`
- Usuario: `admin`
- Contraseña: el valor de `GRAFANA_PASSWORD` en tu `.env`

---

## Importar dashboards

En Grafana → Dashboards → Import, importar por ID:

| Dashboard | ID | Qué muestra |
|-----------|----|-------------|
| Node Exporter Full | `1860` | CPU, RAM, disco, red del servidor |
| Blackbox Exporter | `7587` | Uptime y latencia HTTP |
| Loki Logs | `13639` | Explorador de logs por container |

---

## Configurar alertas por Telegram

### Paso 1 — Crear el bot

1. Abrir `@BotFather` en Telegram
2. Enviar `/newbot` y seguir los pasos
3. Guardar el **Bot Token** (formato `123456789:AAAA...`)

### Paso 2 — Obtener el Chat ID

1. Agregar el bot al grupo o canal de alertas
2. Enviar un mensaje en el grupo
3. Abrir `https://api.telegram.org/bot<TOKEN>/getUpdates`
4. Copiar el `id` del objeto `chat` (negativo para grupos)

### Paso 3 — Configurar en Grafana

1. Grafana → Alerting → Contact Points → Add contact point
2. Tipo: **Telegram**
3. Completar Bot Token y Chat ID
4. Guardar y hacer click en **Test** para verificar

### Paso 4 — Crear notification policy

1. Grafana → Alerting → Notification policies
2. Editar la política default → Contact point: Telegram
3. Guardar

---

## Comandos útiles

```bash
# Ver logs del stack de monitoring
docker compose -f docker-compose.monitoring.yml logs -f

# Reiniciar un servicio específico
docker compose -f docker-compose.monitoring.yml restart grafana

# Actualizar imágenes
docker compose -f docker-compose.monitoring.yml pull
docker compose -f docker-compose.monitoring.yml up -d

# Bajar el stack (los datos persisten en volumes)
docker compose -f docker-compose.monitoring.yml down

# Bajar y borrar datos (cuidado)
docker compose -f docker-compose.monitoring.yml down -v
```

## Monitoreo externo complementario (recomendado)

UptimeRobot (gratis) hace health checks desde fuera del servidor — si el servidor entero cae, Grafana también cae y no puede alertar. UptimeRobot sí avisa.

1. Crear cuenta en [uptimerobot.com](https://uptimerobot.com)
2. Add Monitor → HTTP(s) → URL: `https://app.passix.com.ar`
3. Intervalo: 5 minutos
4. Alertas: email o Telegram

---

## Estructura de archivos

```
monitoring/
├── docker-compose.monitoring.yml
├── .env.example
├── prometheus/
│   ├── prometheus.yml       ← scrape targets
│   ├── alert-rules.yml      ← reglas de alertas
│   └── blackbox.yml         ← módulos HTTP
├── loki/
│   └── loki-config.yml      ← retención 15 días
├── promtail/
│   └── promtail-config.yml  ← recolector de logs Docker
└── grafana/
    └── provisioning/
        ├── datasources/
        │   └── datasources.yml   ← Prometheus + Loki auto-conectados
        └── dashboards/
            └── dashboards.yml    ← directorio de dashboards
```
