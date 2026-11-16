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

La interfaz administrativa permite asignar o revocar permisos por usuario GLPI. El receptor Long Polling, el endpoint HTTPS autenticado, la cola con leases y el prototipo Windows ya están incorporados en las versiones 0.2.44–0.2.57. Aún falta la validación integral del agente en Windows Server 2022 con una cuenta AD de laboratorio y la revisión final de permisos mínimos.

## Etapas de implementación

1. Persistencia, autorización GLPI, vínculo de chat y confirmación Telegram: implementados.
2. Receptor Telegram independiente del cron: implementado con Long Polling de 25 segundos.
3. API del agente: implementada con HMAC-SHA256, timestamp ±60 segundos, nonce único de 10 minutos, `claim` y `complete`.
4. Cola de ejecución: leases de 90 segundos, reintentos hasta tres veces y validación de token de lease.
5. Idempotencia de comandos: cada update_id de Telegram se almacena con índice único para evitar solicitudes duplicadas ante reintentos de Long Polling.
6. Agente Windows PowerShell 5.1: prototipo incluido; sintaxis validada en Windows CI, pendiente ejecución real en laboratorio AD.
7. Pendiente: pruebas integrales en Windows Server 2022, cuenta AD de laboratorio, delegación mínima y despliegue controlado.


## Receptor Telegram CLI (0.2.44)

El proceso persistente se inicia desde la raíz GLPI:

`php plugins/telegram/bin/telegram-receiver.php`

En una instalación Marketplace, ajuste la ruta al directorio real del plugin. El proceso usa un lock para impedir dos receptores simultáneos, guarda el offset en la tabla de configuración y se detiene si detecta que el bot tiene un webhook activo. Debe ejecutarse como servicio independiente (systemd en Linux o servicio/gestor de procesos en Windows), no como cron GLPI.

Los comandos solo se aceptan desde un chat privado cuyo Chat ID esté asociado a un usuario GLPI activo y autorizado. /estado crea una solicitud en cola; /desbloquear presenta botones de confirmación y cancelación. La respuesta final de AD depende de que el agente Windows y su API estén implementados y conectados; esta versión todavía no ejecuta operaciones AD.


## Agente Windows de laboratorio (0.2.50)

Se incorporan los scripts `windows/Agent.ps1` y `windows/Install-AgentSecret.ps1`. El agente consulta la API cada dos segundos, firma cada petición HMAC y solo implementa dos operaciones cerradas: consulta Enabled/LockedOut y desbloqueo con verificación posterior. No evalúa texto, argumentos ni comandos PowerShell recibidos por HTTP.

El instalador cifra el secreto mediante DPAPI LocalMachine y restringe la ACL de `C:\ProgramData\SongaADAgent` a SYSTEM, Administrators y la cuenta de servicio configurada. La URL debe ser HTTPS y apuntar al endpoint del plugin.

**No se debe reemplazar todavía el servicio SongaADAgent existente.** Los scripts son una entrega de laboratorio; falta probarlos en Windows Server 2022 con una cuenta AD de laboratorio, confirmar el comportamiento de DPAPI/ACL y medir latencia. La cuenta de servicio debe tener únicamente los permisos AD delegados que se definan para las OU autorizadas.


## API HTTPS del agente (0.2.48–0.58)

Endpoint: `POST /plugins/telegram/front/ad-agent.php` (también admite el directorio `marketplace`). El agente debe conectarse por HTTPS y enviar `Content-Type: application/json` más los encabezados `X-FML-Agent`, `X-FML-Timestamp`, `X-FML-Nonce` y `X-FML-Signature`.

La firma HMAC-SHA256 se calcula sobre seis líneas separadas por LF (`\n`): método POST, ruta exacta, timestamp Unix, nonce hexadecimal de 32 caracteres, AgentId y SHA-256 hexadecimal del cuerpo HTTP exacto. GLPI rechaza timestamps fuera de ±60 segundos y nonces repetidos; los nonces se conservan durante 10 minutos.

Acciones JSON:
- `{"action":"claim"}`: reclama como máximo una solicitud y devuelve request_uuid, operation, sam_account_name y lease_token.
- `{"action":"complete","request_uuid":"...","lease_token":"...","result_code":"...","data":{...}}`: finaliza solo el lease vigente. Para `account_status`, `data` debe contener booleanos `enabled` y `locked_out`; para `unlock_account`, `data` debe ser un objeto vacío y el código debe estar en la lista permitida.

El secreto HMAC de 256 bits se genera desde Configuración de FML Telegram, se cifra en GLPI con sodium y se muestra una sola vez al administrador. El agente lo protege con DPAPI LocalMachine y ACL de Windows. La respuesta final se envía directamente al chat privado original, sin esperar al cron de notificaciones.

La tabla de solicitudes almacena `telegram_update_id` con índice único. Esto evita que un mismo update de Telegram genere más de una operación si el receptor repite un mensaje tras un error transitorio.


## Límites operativos

Para reducir abuso y evitar saturar el agente, FML Telegram permite hasta 10 consultas de estado por usuario GLPI y chat privado en una ventana de un minuto, y hasta 3 solicitudes de desbloqueo en cinco minutos. Cada desbloqueo requiere confirmación independiente y de un solo uso.
