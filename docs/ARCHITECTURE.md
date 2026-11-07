# FML Telegram — Arquitectura y contrato de integración

## 1. Propósito

FML Telegram es el servicio común de notificaciones de la instalación GLPI de Songa. Será utilizado por:

- GLPI nativo: tickets, asignaciones, cambios de estado, SLA, inventario y otros eventos que se habiliten.
- Otros plugins instalados o futuros, únicamente cuando se autorice expresamente su integración.

Los módulos consumidores mantienen su lógica funcional. FML Telegram se responsabiliza de aceptar, enrutar, almacenar y entregar notificaciones.

## 2. Decisiones confirmadas

| Elemento | Decisión |
|---|---|
| Nombre visible | FML Telegram |
| Identificador técnico | `telegram` |
| Repositorio | `fmosquera01/telegram` |
| Rama | `main` |
| GLPI objetivo | `11.0.11` |
| Transporte | Telegram Bot API por HTTPS |
| Zona horaria predeterminada | `America/Guayaquil` |
| Alcance | GLPI nativo y todos los plugins |
| Bot | Un bot central compartido |
| Entrega | Cola persistente y tarea programada |
| Datos | Tablas propias con prefijo `glpi_plugin_telegram_` |

## 3. Componentes internos

### 3.1 Configuración y secretos

- Token del bot ingresado y mostrado siempre como campo vacío/mascarado; nunca volver a renderizar el secreto.
- Cifrado autenticado mediante Sodium, si la extensión está disponible.
- Clave de cifrado aleatoria guardada fuera de la base de datos, dentro de un directorio privado de `GLPI_VAR_DIR`, con permisos restrictivos.
- Si la clave no existe o no puede validarse, no intentar descifrar ni reemplazar el token silenciosamente.
- Tiempos de espera, límites de reintento y parámetros operativos.
- El token nunca debe aparecer en la interfaz, logs, historial ni mensajes de depuración; se transmite únicamente por HTTPS a `api.telegram.org`.

### 3.2 Destinos

El servicio debe permitir administrar destinos Telegram independientes del origen de la notificación:

- Chat privado asociado opcionalmente a un usuario GLPI.
- Grupo o supergrupo.
- Canal, si el bot tiene permisos para publicar.
- Destinos activos/inactivos.
- Alias descriptivo y metadatos mínimos.
- Suscripciones por origen y evento.

No asumir que todo usuario GLPI tiene Chat ID ni que todos los destinos son numéricos positivos; Telegram admite identificadores de chat negativos para grupos. Validar según la respuesta real de Telegram.

### 3.3 Enrutamiento

Cada notificación identifica:

- `source`: identificador técnico del productor, por ejemplo `glpi` o `plugin_demo`.
- `event`: evento estable, por ejemplo `ticket.created`, `ticket.assigned`, `ticket.sla_changed` o `hardware.changed`.
- `subject`: resumen breve.
- `message`: texto con formato admitido.
- `recipients` (alias de destino o `user:<id>`) o regla de suscripción.
- `deduplication_key`: clave opcional para evitar duplicados.

La configuración debe permitir habilitar/deshabilitar una suscripción por origen/evento y asociarla a uno o más destinos. El servicio no debe inventar destinatarios cuando no exista una regla explícita.

## 4. API pública para plugins

La API se ofrece mediante clases PHP con namespace estable. El ejemplo detallado para consumidores está en [docs/API.md](API.md):

```php
\GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'plugin_demo',
    'event' => 'hardware.changed',
    'subject' => 'Cambio de hardware detectado',
    'message' => $message,
    'recipients' => ['infraestructura'],
    'deduplication_key' => $eventKey,
]);
```

La API está implementada en `src/NotificationService.php` y expone `NotificationService::getApiVersion()` (API `1.0`). Devuelve `queued`, `skipped` y `errors`; si no se indica `recipients`, resuelve los destinos activos suscritos a la pareja `source`/`event`. Si se indican aliases en `recipients`, esos destinos se usan directamente; también se admite `user:<id>` para resolver destinos asociados a un usuario GLPI. La operación solo encola; no realiza HTTP.

Requisitos del contrato:

- Validar estructura, longitudes y origen.
- El consumidor debe comprobar que `GlpiPlugin\\Telegram\\NotificationService` está disponible antes de invocarla.
- Persistir la solicitud y devolver un resultado controlado.
- No enviar directamente por HTTP desde el productor.
- No lanzar una excepción que interrumpa el flujo de GLPI por un fallo del transporte.
- Permitir consultar disponibilidad/versión de la API para compatibilidad entre plugins.
- No aceptar token ni URL arbitraria del productor.
- El contrato público de encolado no ofrece una operación para consultar el token central; las clases de transporte y almacenamiento son internas al plugin.

## 5. Cola y entrega

La cola debe almacenar estado y trazabilidad por notificación/destino:

- `pending`, `processing`, `sent`, `retry`, `failed`.
- Número de intentos, próxima ejecución y último error saneado.
- Identificador de mensaje devuelto por Telegram, cuando exista.
- Fechas de creación, procesamiento y entrega.
- Clave de idempotencia/deduplicación.

Una tarea cron de GLPI procesará lotes limitados. Debe contemplar bloqueos para evitar que dos ejecuciones procesen el mismo registro, backoff progresivo, límite de intentos y recuperación de registros que queden en `processing` por una interrupción.

Un error de Telegram nunca debe revertir la transacción funcional de GLPI. El historial no se elimina al alcanzar el límite de intentos.

## 6. Integración con GLPI nativo

Los eventos nativos se implementan mediante puntos de extensión documentados de GLPI 11.0.11. Antes de registrar cada evento se debe comprobar en el código fuente de esa versión:

- Nombre y firma del hook/evento.
- Momento de ejecución respecto de la persistencia del objeto.
- Si se ejecuta para cambios de inventario, procesos automáticos o acciones manuales.
- Riesgo de recursión y duplicación.
- Datos disponibles para construir el mensaje.

No se asumirá que un hook genérico cubre todos los casos. Se habilitarán eventos individualmente y con pruebas automatizadas o de integración.

Eventos nativos implementados inicialmente:
- `ticket.created`: creación de ticket.
- `ticket.assigned`: asignación de técnico o grupo asignado.
- `ticket.status_changed`: cambio de estado.
- `ticket.priority_changed`: cambio de prioridad.\n- `ticket.sla_changed`: modificación de asignación SLA o de sus fechas límite.

SLA, inventario, usuarios y otros eventos todavía no están implementados.

## 7. Seguridad y permisos

- La primera versión restringe la administración al permiso de configuración de GLPI; aún falta granularidad de permisos para separar configuración, consulta del historial y envío.
- Validar sesión, permisos y CSRF en formularios.
- Enviar texto plano sin habilitar `parse_mode`, limitar el tamaño del mensaje y normalizar UTF-8.
- HTTPS con validación de certificado habilitada.
- Timeouts de conexión y respuesta.
- No permitir que plugins consumidores proporcionen endpoints HTTP.
- Sanitizar errores antes de guardarlos y mostrarlos.
- Proteger formularios y endpoints contra solicitudes no autorizadas.
- Evitar SSRF: el destino HTTP será fijo (`https://api.telegram.org`).

## 8. Integración de plugins consumidores

Los plugins consumidores podrán llamar a la API PHP pública de FML Telegram para encolar notificaciones. La API no acepta tokens, credenciales ni endpoints externos. Cada consumidor deberá indicar un `source` estable y un `event` documentado.

La integración de cada plugin consumidor será un trabajo separado y requerirá autorización explícita. El desarrollo de FML Telegram no modifica otros repositorios ni intenta migrar configuraciones de otros plugins.

## 9. Persistencia implementada

La primera versión de desarrollo crea estas tablas:

- `glpi_plugin_telegram_destinations`: destinos de Telegram, alias y asociación opcional a `glpi_users`.
- `glpi_plugin_telegram_subscriptions`: reglas origen/evento/destino.
- `glpi_plugin_telegram_queue`: cola y estado actual de cada entrega.
- `glpi_plugin_telegram_delivery_logs`: registro de cada intento de entrega.
- `glpi_plugin_telegram_configs`: token cifrado.

La cola conserva los estados `pending`, `processing`, `retry`, `sent` y `failed`, con intentos, fechas, Telegram message ID y último error saneado. El panel muestra el historial de cola y los intentos individuales, incluyendo resultado, Telegram message ID y error saneado.

Las tablas se crean con `CREATE TABLE IF NOT EXISTS`. La desinstalación conserva deliberadamente los datos y elimina la tarea cron registrada por el plugin. La clave privada debe incluirse en el respaldo seguro de `GLPI_VAR_DIR/_plugins/telegram/`; perderla impide descifrar el token almacenado.

## 10. Criterios de aceptación

- Instalación y actualización repetibles en GLPI 11.0.11.
- El token no queda en texto plano en base de datos ni logs.
- Un plugin consumidor puede encolar una notificación sin conocer credenciales.
- GLPI nativo puede encolar al menos los eventos nativos habilitados.
- La cola soporta reintentos, duplicados, fallos y recuperación tras interrupción.
- La caída de Telegram no bloquea creación/actualización de tickets ni inventarios.
- Destinos y suscripciones se administran desde GLPI.
- El historial permite identificar origen, evento, destino y resultado.
- Pruebas de permisos, CSRF, validación, instalación, actualización y desinstalación.
- No se solicita al usuario instalar ni probar hasta que la implementación esté completa y revisada.

## 11. Estado actual — versión de desarrollo 0.2.12

Ya existe una primera implementación de instalación, cifrado del token, panel de configuración, gestión de destinos con asociación opcional a usuario GLPI, suscripciones, historial con reintento manual, API de encolado, worker cron y notificaciones nativas iniciales para tickets.

No se considera lista para instalar. La administración ya permite editar destinos y filtrar/paginar el historial de entregas. Aún faltan eventos nativos de inventario y vencimiento de SLA, además de pruebas de integración y validación de instalación/operación real sobre GLPI 11.0.11. No solicitar pruebas en el ambiente de producción ni de pruebas hasta confirmar expresamente que la versión está lista.
