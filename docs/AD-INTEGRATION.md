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
- /bloqueados lista únicamente usuarios Enabled=true y LockedOut=true, en páginas de 10; cada selección crea un desbloqueo individual con confirmación de un solo uso.
- /estado usuario consulta el estado; /desbloquear usuario permite solicitar directamente una cuenta y requiere confirmación explícita de un solo uso.
- Las cuentas deshabilitadas no se muestran en el listado y nunca se habilitan ni desbloquean; el agente vuelve a validar Enabled inmediatamente antes de ejecutar.
- Cada transición y resultado queda auditado.
- La cuenta del agente tendrá permisos mínimos. No se conservará una membresía de Domain Admins para hacer funcionar el servicio.

## Contrato funcional

### Comandos

- /bloqueados: busca cuentas bloqueadas con `Search-ADAccount -LockedOut -UsersOnly`, consulta `Enabled` y devuelve exclusivamente cuentas habilitadas. Telegram muestra 10 por página; cada botón de cuenta se valida contra el snapshot auditado antes de crear la solicitud.
- /estado usuario: consulta Enabled y LockedOut.
- /desbloquear usuario: crea una solicitud que expira si no se confirma. Antes de desbloquear, el agente comprueba que `Enabled = True`; si la cuenta está deshabilitada, devuelve `account_disabled` y no ejecuta `Unlock-ADAccount`.
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
- `{"action":"complete","request_uuid":"...","lease_token":"...","result_code":"...","data":{...}}`: finaliza solo el lease vigente. Para `account_status`, `data` debe contener booleanos `enabled` y `locked_out`; para `list_locked_accounts`, `data.accounts` contiene como máximo 200 objetos con `sam_account_name` y `display_name`; para `unlock_account`, `data` debe ser un objeto vacío y el código debe estar en la lista permitida (`unlocked`, `already_unlocked`, `account_disabled`, `not_found`, `access_denied`, `error`).

El secreto HMAC de 256 bits se genera desde Configuración de FML Telegram, se cifra en GLPI con sodium y se muestra una sola vez al administrador. El agente lo protege con DPAPI LocalMachine y ACL de Windows. La respuesta final se envía directamente al chat privado original, sin esperar al cron de notificaciones.

La tabla de solicitudes almacena `telegram_update_id` con índice único. Esto evita que un mismo update de Telegram genere más de una operación si el receptor repite un mensaje tras un error transitorio.


## Límites operativos

Para reducir abuso y evitar saturar el agente, FML Telegram permite hasta 10 consultas de estado por usuario GLPI y chat privado en una ventana de un minuto, y hasta 3 solicitudes de desbloqueo en cinco minutos. Cada desbloqueo requiere confirmación independiente y de un solo uso.


Si el envío inmediato del resultado desde el endpoint falla, GLPI intenta encolar un aviso alternativo dirigido únicamente al usuario GLPI solicitante, con clave de deduplicación basada en request_uuid. El envío normal sigue siendo directo e inmediato.


El script de provisión ya no tiene una URL predeterminada: se debe indicar explícitamente `-ApiUrl` con el endpoint HTTPS del entorno de laboratorio. Así se evita apuntar accidentalmente el agente al GLPI de producción.


En GLPI 11, el endpoint `front/ad-agent.php` se registra como ruta stateless desde `plugin_telegram_boot()`. Esto evita que GLPI inicie una sesión web y redirija al login las peticiones HMAC del agente Windows.


## Registro y autorización de agentes (0.2.69)

Los agentes Windows se administran desde **FML Telegram → Configuración → Agentes de Active Directory**. Cada agente tiene un `agent_id` único, un nombre, un controlador de dominio y un secreto HMAC individual cifrado por el plugin.

- Registrar un agente genera un secreto que se muestra una sola vez. Debe provisionarse en Windows con el instalador protegido; no debe compartirse con otro agente.
- El estado inicial es `pending`. Las peticiones HMAC de agentes pendientes o revocados reciben `401 unauthorized`.
- Un administrador con permiso de configuración de GLPI debe aprobar explícitamente el agente.
- El agente predeterminado aprobado recibe las nuevas solicitudes AD. La columna `target_agent_id` conserva el destino; `claim` solo reclama solicitudes cuyo destino coincide exactamente con el ID autenticado.
- Revocar un agente bloquea su autenticación. Para incorporar un reemplazo, registrar un nuevo identificador y aprobarlo.
- La actualización migra el secreto legado a `SVRSNGPRTG-LAB` como agente pendiente, conservando el cifrado existente. La migración no lo aprueba automáticamente.

La cola no debe procesarse hasta que el agente de laboratorio esté aprobado y se haya validado el aislamiento en GLPI de pruebas.


## Listado y selección de cuentas bloqueadas (0.2.78)

`/bloqueados` encola `list_locked_accounts`. El agente consulta las cuentas bloqueadas en el controlador de dominio y filtra nuevamente cada objeto para exigir `Enabled = True` y `LockedOut = True`. Se limita el snapshot a 200 cuentas para mantener el registro de auditoría acotado. GLPI conserva el snapshot en el evento de auditoría `request_completed`; la navegación muestra 10 cuentas por página. Cada botón de selección se valida contra el snapshot, el chat privado y el usuario GLPI que originó la consulta. La selección crea una solicitud `unlock_account` separada con token de confirmación de un solo uso y vencimiento de cinco minutos.

La operación directa `/desbloquear usuario` permanece disponible. Tanto en el flujo directo como en el flujo de selección, el agente vuelve a consultar el usuario en AD inmediatamente antes de desbloquear. Si `Enabled` es falso, devuelve `account_disabled`; no se ejecuta `Unlock-ADAccount` ni se cambia ningún otro atributo. La auditoría identifica al solicitante GLPI, la cuenta, la confirmación, el agente, el resultado y la fecha de cada transición.
