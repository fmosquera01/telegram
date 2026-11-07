# FML Telegram — Guía preliminar de instalación y operación

> Esta guía documenta el flujo previsto. La versión actual sigue en desarrollo y no debe instalarse ni probarse hasta recibir confirmación expresa.

## Requisitos

- GLPI 11.0.11.
- PHP 8.2 o superior.
- Extensiones PHP `curl`, `sodium` y `mbstring`.
- Acceso de escritura de la cuenta de servicio de GLPI a `GLPI_VAR_DIR`.
- Salida HTTPS desde el servidor GLPI hacia `api.telegram.org`.

## Instalación prevista

1. Respaldar la base de datos GLPI y el directorio `GLPI_VAR_DIR`.
2. Copiar el directorio `telegram` dentro de `GLPI/plugins/`.
3. Desde **Configuración > Plugins**, instalar y activar **FML Telegram**.
4. Verificar que la tarea automática de envío `GlpiPlugin\Telegram\Worker::send` esté registrada en las acciones automáticas.
5. Configurar la ejecución externa del cron de GLPI.

## Cron externo

El worker se registra en modo externo y procesa hasta 20 notificaciones por ejecución. Para una instalación Linux, la tarea del sistema debe invocar `front/cron.php` cada minuto con el usuario de servicio de GLPI. Ejemplo con rutas que deben adaptarse:

```cron
* * * * * www-data /usr/bin/php /ruta/a/glpi/front/cron.php
```

En Windows, crear una tarea programada que ejecute `php.exe` con el argumento absoluto `GLPI\front\cron.php`, con repetición cada minuto y usando la cuenta del servicio web.

## Configuración inicial

1. Abrir **Plugins > FML Telegram** con una cuenta que tenga permiso de configuración.
2. Guardar el token de BotFather. El token se cifra con Sodium y la clave privada se guarda en `GLPI_VAR_DIR/_plugins/telegram/`.
3. Usar **Probar conexión con Telegram** para validar el token.
4. Crear los destinos: Chat ID numérico (incluidos los negativos de grupos) o nombre público de canal `@canal`.
5. Asociar opcionalmente cada destino a un usuario GLPI.
6. Crear las suscripciones de origen y evento.
7. Usar **Probar** en el destino para encolar un mensaje de prueba; el cron lo entregará.

## Respaldo del secreto

El archivo `GLPI_VAR_DIR/_plugins/telegram/telegram.key` es necesario para descifrar el token. Debe incluirse en el respaldo seguro de `GLPI_VAR_DIR`. Si se pierde, el token almacenado no podrá recuperarse y deberá ingresarse nuevamente.

## Eventos nativos disponibles en esta versión

- `glpi` / `ticket.created`
- `glpi` / `ticket.assigned`
- `glpi` / `ticket.status_changed`
- `glpi` / `ticket.priority_changed`
- `glpi` / `ticket.sla_changed`
- `glpi` / `inventory.received` (solo tras una importación exitosa de inventario)
- `glpi` / `ticket.sla_due` (fecha límite en los próximos 30 minutos)
- `glpi` / `ticket.sla_overdue` (fecha límite vencida)

La tarea automática SLA se ejecuta cada cinco minutos y revisa tickets abiertos con fecha límite dentro de los próximos 30 minutos o vencida. El evento `inventory.received` notifica únicamente después de una importación exitosa. Las integraciones con otros plugins son trabajos separados y requieren autorización expresa.

## Operación y diagnóstico

- La cola conserva `pending`, `processing`, `retry`, `sent` y `failed`.
- Los errores de Telegram se reintentan con backoff exponencial, hasta ocho intentos.
- Los intentos individuales se registran en `glpi_plugin_telegram_delivery_logs`.
- El panel permite reintentar registros fallidos o pendientes.
- Si Telegram no está disponible, la operación funcional de GLPI no debe esperar ni fallar por el transporte.

