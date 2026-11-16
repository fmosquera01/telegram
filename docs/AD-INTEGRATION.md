# Integración Telegram ↔ GLPI ↔ Active Directory

## Objetivo

Permitir consultas de estado y desbloqueos de cuentas AD desde Telegram, con autorización GLPI, confirmación explícita, ejecución limitada en un agente Windows y auditoría completa.

## Requisitos no negociables

- El cron de notificaciones de FML Telegram permanece sin cambios y solo procesa notificaciones.
- Telegram se recibe en un proceso persistente independiente usando Long Polling, o mediante webhook dedicado.
- El agente Windows establece únicamente conexiones HTTPS salientes hacia GLPI. No se abre un puerto entrante en PRTG.
- Ningún mensaje de Telegram se transforma en una línea de PowerShell ni en un comando arbitrario.
- Solo se admiten Chat ID privados activos vinculados a usuarios GLPI autorizados.
- El usuario GLPI debe estar activo y contar con autorización específica para AD.
- /estado usuario consulta el estado; /desbloquear usuario requiere confirmación explícita de un solo uso.
- Cada transición y resultado queda auditado.
- La cuenta del agente tendrá permisos mínimos. No se conservará una membresía de Domain Admins para hacer funcionar el servicio.

## Contrato funcional

### Comandos

- /estado usuario: consulta Enabled y LockedOut.
- /desbloquear usuario: crea una solicitud que expira si no se confirma.
- Confirmación: acción Telegram asociada a un token aleatorio de 32 caracteres, de un solo uso, almacenado únicamente como hash y compatible con el límite de 64 bytes de callback_data de Telegram.

### Estados de solicitud

pending_confirmation → queued → claimed → completed o failed.

Estados terminales adicionales: expired y cancelled. Una solicitud reclamada usa un lease temporal para recuperarse de reinicios del agente sin ejecutar dos veces la misma solicitud.

### Datos de ejecución

El agente recibe un identificador de solicitud, operación permitida, cuenta y fecha de expiración. Devuelve un código de resultado y datos estructurados mínimos. No recibe el token del bot, credenciales de GLPI ni comandos libres.

## Persistencia y autorización incorporadas en 0.2.36

- glpi_plugin_telegram_ad_requests: cola y ciclo de vida de solicitudes.
- glpi_plugin_telegram_ad_audit: auditoría de eventos por solicitud.
- glpi_plugin_telegram_ad_authorizations: permisos explícitos por usuario GLPI para consultar estado y desbloquear cuentas.
- AdRequestService: comprueba vínculo de chat privado, usuario activo y permiso específico; los desbloqueos quedan pendientes de confirmación por cinco minutos y el token de un solo uso se almacena únicamente como hash.

Esta entrega todavía no implementa endpoints HTTP, Long Polling, autenticación HMAC del agente ni operaciones PowerShell. La interfaz administrativa ya permite asignar o revocar permisos por usuario GLPI desde Configuración de FML Telegram. La confirmación se identifica mediante un token de 32 caracteres y el Chat ID privado original; el token se consume de forma atómica y no puede reutilizarse. El esquema queda preparado para implementar y probar cada capa por separado.

## Etapas de implementación

1. Persistencia y estados de solicitud (esta entrega).
2. Autorización GLPI, asociación segura Chat ID ↔ usuario y flujo de confirmación Telegram.
3. Receptor Telegram persistente independiente del cron, implementado como proceso CLI con getUpdates Long Polling (espera de hasta 25 segundos) y callback queries para confirmar o cancelar acciones.
4. API HTTPS de agente con HMAC, timestamp, protección contra replay, leases e idempotencia.
5. Agente Windows con consulta de estado y desbloqueo limitado.
6. Pruebas integrales en GLPI de pruebas y cuenta AD de laboratorio; el smoke test de GLPI valida la creación de las tablas AD y la carga del servicio.
7. Revisión de permisos mínimos y despliegue controlado.


## Receptor Telegram CLI (0.2.44)

El proceso persistente se inicia desde la raíz GLPI:

`php plugins/telegram/bin/telegram-receiver.php`

En una instalación Marketplace, ajuste la ruta al directorio real del plugin. El proceso usa un lock para impedir dos receptores simultáneos, guarda el offset en la tabla de configuración y se detiene si detecta que el bot tiene un webhook activo. Debe ejecutarse como servicio independiente (systemd en Linux o servicio/gestor de procesos en Windows), no como cron GLPI.

Los comandos solo se aceptan desde un chat privado cuyo Chat ID esté asociado a un usuario GLPI activo y autorizado. /estado crea una solicitud en cola; /desbloquear presenta botones de confirmación y cancelación. La respuesta final de AD depende de que el agente Windows y su API estén implementados y conectados; esta versión todavía no ejecuta operaciones AD.
