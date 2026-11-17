# FML Telegram — Guía preliminar de instalación y operación

> FML Telegram 0.2.83 mantiene la prueba automatizada de instalación/activación e integración en GLPI 11.0.11 con MariaDB 10.11. La provisión del agente Windows se documenta en [AD-WINDOWS-DEPLOYMENT.md](AD-WINDOWS-DEPLOYMENT.md). La conexión con Telegram se valida después de configurar el token en el ambiente de pruebas.

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
4. Verificar que las tareas automáticas `GlpiPlugin\Telegram\Worker::send` y `GlpiPlugin\Telegram\Worker::sla` estén registradas en las acciones automáticas.
5. Configurar la ejecución externa del cron de GLPI.

## Cron externo

El worker se registra en modo externo y procesa hasta 20 notificaciones por ejecución; la tarea `sla` se ejecuta cada cinco minutos y revisa fechas límite próximas o vencidas. Para una instalación Linux, la tarea del sistema debe invocar `front/cron.php` cada minuto con el usuario de servicio de GLPI. Ejemplo con rutas que deben adaptarse:

```cron
* * * * * www-data /usr/bin/php /ruta/a/glpi/front/cron.php
```

En Windows, crear una tarea programada que ejecute `php.exe` con el argumento absoluto `GLPI\front\cron.php`, con repetición cada minuto y usando la cuenta del servicio web.

## Configuración inicial

1. Abrir **Plugins > FML Telegram** con una cuenta que tenga permiso de configuración. La administración está dividida en **Notificaciones**, **Cola de salida** y **Active Directory**.
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



## Validación de Active Directory en laboratorio

1. En **Active Directory**, registrar un agente con un identificador exclusivo y aprobarlo.
2. En la tabla de agentes, comprobar que el diagnóstico del secreto indique **Recuperable**. Si muestra otro código, resolver primero el almacenamiento/clave GLPI; no volver a registrar el agente ni regenerar el secreto.
3. Instalar el secreto emitido al registrar el agente mediante el procedimiento de instalación del agente. El secreto solo se muestra una vez.
4. Ejecutar la prueba HMAC segura desde el host de laboratorio. El resultado esperado es HTTP 400 `unsupported_action`; indica que HMAC fue aceptado y que la acción de prueba no ejecutó ninguna operación AD.
5. Si devuelve HTTP 401, consultar el log de errores PHP/Apache del servidor GLPI. Los códigos posibles incluyen `agent_not_approved`, `secret_not_found`, `encryption_key_missing`, `secret_decryption_failed` y `signature_mismatch`. Estos códigos no contienen secretos.
6. Si el agente autentica correctamente, realizar primero una consulta de estado con una cuenta de laboratorio autorizada. Probar desbloqueos solo con una cuenta de prueba y confirmación explícita.
7. En Telegram privado, probar `/bloqueados`: el listado debe incluir únicamente cuentas `Enabled = True` y `LockedOut = True`, en páginas de hasta 10 cuentas.
8. Seleccionar una cuenta de laboratorio; verificar que aparece confirmación individual de cinco minutos. Confirmar y comprobar en AD que se desbloqueó.
9. Probar una cuenta deshabilitada: debe quedar fuera de `/bloqueados`; si se intenta `/desbloquear usuario`, el agente debe responder que está deshabilitada y que no realizó cambios.

La prueba automatizada de secretos puede ejecutarse desde la raíz del repositorio con `php tests/agent-secret-store.php`. Utiliza almacenamiento temporal aislado y no toca la clave ni los secretos reales de GLPI.
