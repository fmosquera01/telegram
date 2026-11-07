# FML Telegram — Arquitectura y contrato de integración

## 1. Propósito

FML Telegram es el servicio común de notificaciones de la instalación GLPI de Songa. Será utilizado por:

- GLPI nativo: tickets, asignaciones, cambios de estado, SLA, inventario y otros eventos que se habiliten.
- Plugins existentes: InformeGLPI, Control HW, ActaEntrega, HH.
- Plugins futuros que implementen el contrato público.

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
- Configuración de URL base de GLPI, tiempos de espera, límites de reintento y parámetros operativos.
- El token nunca debe aparecer en logs, excepciones, URLs visibles, historial ni mensajes de depuración.

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

- `source`: identificador técnico del productor, por ejemplo `glpi`, `informeglpi`, `controlhw`, `actadeentrega` o `hh`.
- `event`: evento estable, por ejemplo `ticket.created`, `ticket.assigned` o `hardware.changed`.
- `subject`: resumen breve.
- `message`: texto con formato admitido.
- `recipients` o regla de destino.
- `deduplication_key`: clave opcional para evitar duplicados.
- `metadata`: datos estructurados mínimos, sin secretos.

La configuración debe permitir habilitar/deshabilitar una suscripción por origen/evento y asociarla a uno o más destinos. El servicio no debe inventar destinatarios cuando no exista una regla explícita.

## 4. API pública para plugins

La API se implementará como clases PHP con namespace estable:

```php
\GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'controlhw',
    'event' => 'hardware.changed',
    'subject' => 'Cambio de hardware detectado',
    'message' => $message,
    'recipients' => ['infraestructura'],
    'deduplication_key' => $eventKey,
]);
```

Este ejemplo expresa el contrato previsto; no representa todavía una API implementada.

Requisitos del contrato:

- Validar estructura, longitudes y origen.
- Confirmar que el plugin central está instalado y disponible.
- Persistir la solicitud y devolver un resultado controlado.
- No enviar directamente por HTTP desde el productor.
- No lanzar una excepción que interrumpa el flujo de GLPI por un fallo del transporte.
- Permitir consultar disponibilidad/versión de la API para compatibilidad entre plugins.
- No aceptar token ni URL arbitraria del productor.
- Los consumidores no podrán leer ni descifrar el token central.

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

Los eventos nativos se implementarán mediante puntos de extensión documentados de GLPI 11.0.11. Antes de registrar cada evento se debe comprobar en el código fuente de esa versión:

- Nombre y firma del hook/evento.
- Momento de ejecución respecto de la persistencia del objeto.
- Si se ejecuta para cambios de inventario, procesos automáticos o acciones manuales.
- Riesgo de recursión y duplicación.
- Datos disponibles para construir el mensaje.

No se asumirá que un hook genérico cubre todos los casos. Se habilitarán eventos individualmente y con pruebas automatizadas o de integración.

Eventos candidatos iniciales:
- Ticket creado.
- Ticket asignado/reasignado.
- Cambio de estado.
- Cambio de prioridad.
- Próximo o incumplido SLA, usando tareas/eventos compatibles.
- Nuevo inventario o actualización de equipo, evitando avisos por cada inventario sin cambios relevantes.

## 7. Seguridad y permisos

- Separar permisos para administrar configuración/destinos, consultar historial y enviar notificaciones.
- Validar sesión, permisos y CSRF en formularios.
- Escapar contenido HTML para Telegram; limitar tamaño del mensaje y tratar caracteres especiales.
- HTTPS con validación de certificado habilitada.
- Timeouts de conexión y respuesta.
- No permitir que plugins consumidores proporcionen endpoints HTTP.
- Sanitizar errores antes de guardarlos y mostrarlos.
- Proteger formularios y endpoints contra solicitudes no autorizadas.
- Evitar SSRF: el destino HTTP será fijo (`https://api.telegram.org`).

## 8. Migración desde InformeGLPI

La integración actual de InformeGLPI ya contiene lógica de:
- Cifrado Sodium del token y archivo de clave dentro de `GLPI_VAR_DIR`.
- Envío por cURL con verificación TLS y timeouts.
- Chat ID personal almacenado en `glpi_plugin_informeglopi_user_telegram`.
- Mensajes de firma, devolución, finalización y recordatorios programados.

La migración debe ser gradual y sin interrumpir la operación:

1. Implementar y validar FML Telegram sin modificar InformeGLPI.
2. Incorporar una rutina explícita de importación/migración del token y destinos existentes, verificando integridad y evitando registrar secretos.
3. Confirmar que FML Telegram puede enviar a los destinos migrados.
4. Adaptar InformeGLPI para encolar mediante la API central.
5. Mantener compatibilidad o una ruta de reversión hasta completar las pruebas.
6. Retirar el código duplicado de InformeGLPI solo después de validar todos sus flujos y recordatorios.

No copiar ni mover automáticamente el archivo de clave o token. La migración deberá comprobar permisos, formato y disponibilidad del secreto; si no se puede migrar con seguridad, solicitar una nueva captura del token en el panel central.

## 9. Persistencia prevista

Tablas propias, sujetas a revisión de diseño antes de implementarlas:

- `glpi_plugin_telegram_destinations`
- `glpi_plugin_telegram_subscriptions`
- `glpi_plugin_telegram_queue`
- `glpi_plugin_telegram_delivery_logs`
- `glpi_plugin_telegram_configs` (solo si no se usa adecuadamente la configuración nativa de GLPI)

Las tablas deben usar índices para estado/próxima ejecución, origen/evento, destino y deduplicación. Las migraciones deben ser idempotentes. La política predeterminada de desinstalación conservará historial y configuración; no eliminará registros sin una acción explícita y documentada.

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

## 11. Estado actual

Documento de diseño inicial. Aún no hay código instalable ni funcional. La siguiente etapa es inspeccionar los puntos de extensión de GLPI 11.0.11 y terminar el diseño de esquema/API antes de implementar el plugin.
