# FML Telegram — API para plugins consumidores

API actual: `1.1`

El plugin consumidor solo encola la notificación. No debe llamar a Telegram directamente, guardar tokens ni acceder a las clases internas de transporte.

## Ejemplo por suscripción

Configurar previamente en FML Telegram una suscripción con el origen y evento usados por el productor:

- Origen: `plugin_demo`
- Evento: `hardware.changed`
- Destino: el alias registrado, asociado a la suscripción

```php
use GlpiPlugin\Telegram\NotificationService;

if (class_exists(NotificationService::class)) {
    $result = NotificationService::enqueue([
        'source' => 'plugin_demo',
        'event' => 'hardware.changed',
        'subject' => 'Cambio de hardware detectado',
        'message' => $message,
        'parse_mode' => 'HTML', // Opcional: HTML compatible con Telegram.
        'deduplication_key' => 'plugin_demo:hardware.changed:' . $assetId . ':' . $eventVersion,
    ]);
}
```

## Destinatarios explícitos

El productor puede indicar aliases de destinos o usuarios GLPI que tengan un destino asociado:

```php
use GlpiPlugin\Telegram\NotificationService;

$result = NotificationService::enqueue([
    'source' => 'glpi',
    'event' => 'ticket.assigned',
    'subject' => 'Ticket asignado',
    'message' => 'Se asignó un ticket.',
    'recipients' => ['user:42'],
    'include_subscriptions' => true,
]);
```

El ejemplo anterior encola el mensaje para el destino privado vinculado al usuario GLPI 42 y para los destinos suscritos a `glpi / ticket.assigned`.

```php
use GlpiPlugin\Telegram\NotificationService;

$result = NotificationService::enqueue([
    'source' => 'mi_plugin',
    'event' => 'proceso.finalizado',
    'subject' => 'Proceso finalizado',
    'message' => 'El proceso terminó correctamente.',
    'recipients' => ['infraestructura', 'user:42'],
]);
```

- Alias: por ejemplo, `infraestructura`.
- Usuario GLPI: `user:<id>`, por ejemplo `user:42`. Se enviará a todos los destinos activos asociados a ese usuario.
- Si se omite `recipients` o se envía un array vacío, el servicio usa las suscripciones activas configuradas para `source` + `event`.
- Para combinar los destinatarios explícitos con las suscripciones activas del evento, agrega `include_subscriptions => true`. Esto permite notificar a un técnico individual y también a los grupos suscritos.

Los destinatarios explícitos se resuelven directamente y no requieren suscripción previa.

## Formato del mensaje

El campo opcional `parse_mode` acepta actualmente `HTML`. Cuando se omite o se envía `null`, el mensaje se entrega como texto plano, manteniendo la compatibilidad con integraciones existentes.

Con `parse_mode => 'HTML'`, el contenido de `message` puede incluir las etiquetas admitidas por Telegram, como `<b>`, `<strong>`, `<i>`, `<em>`, `<u>`, `<s>`, `<code>`, `<pre>`, `<a href="https://example.com">` y saltos de línea. No se admite HTML de navegador completo, CSS ni JavaScript. Los valores dinámicos deben escaparse con `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` antes de insertarlos en el HTML.

El asunto se añade automáticamente en negrita y escapado antes del cuerpo HTML. Si Telegram rechaza el marcado por un error de formato, FML Telegram convierte el contenido a texto plano legible y realiza un segundo intento. Los errores de conexión y otros errores ajenos al formato no activan el fallback.

## Resultado

La función devuelve un array:

- `queued`: cantidad de entregas insertadas en la cola.
- `skipped`: registros omitidos por deduplicación.
- `errors`: errores de validación o persistencia, incluido el caso en que no exista un destino o suscripción activa. Un fallo de la cola no debe interrumpir el proceso funcional del plugin consumidor.

La operación no espera a Telegram ni realiza HTTP. La tarea cron de GLPI procesa la cola.

## Compatibilidad

Consultar la versión con:

```php
$apiVersion = NotificationService::getApiVersion();
```

La clase `NotificationService` es el único punto de entrada documentado. Las clases de almacenamiento de secretos y transporte son internas y no deben utilizarse desde plugins consumidores.
