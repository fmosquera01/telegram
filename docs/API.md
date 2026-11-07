# FML Telegram — API para plugins consumidores

API actual: `1.0`

El plugin consumidor solo encola la notificación. No debe llamar a Telegram directamente, guardar tokens ni acceder a las clases internas de transporte.

## Ejemplo por suscripción

Configurar previamente en FML Telegram una suscripción con el origen y evento usados por el productor:

- Origen: `controlhw`
- Evento: `hardware.changed`
- Destino: el alias registrado, asociado a la suscripción

```php
use GlpiPlugin\Telegram\NotificationService;

if (class_exists(NotificationService::class)) {
    $result = NotificationService::enqueue([
        'source' => 'controlhw',
        'event' => 'hardware.changed',
        'subject' => 'Cambio de hardware detectado',
        'message' => $message,
        'deduplication_key' => 'controlhw:hardware.changed:' . $assetId . ':' . $eventVersion,
    ]);
}
```

## Destinatarios explícitos

El productor puede indicar aliases de destinos o usuarios GLPI que tengan un destino asociado:

```php
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

Los destinatarios explícitos se resuelven directamente y no requieren suscripción previa.

## Resultado

La función devuelve un array:

- `queued`: cantidad de entregas insertadas en la cola.
- `skipped`: registros omitidos por deduplicación.
- `errors`: errores de validación o persistencia. Un fallo de la cola no debe interrumpir el proceso funcional del plugin consumidor.

La operación no espera a Telegram ni realiza HTTP. La tarea cron de GLPI procesa la cola.

## Compatibilidad

Consultar la versión con:

```php
$apiVersion = NotificationService::getApiVersion();
```

La clase `NotificationService` es el único punto de entrada documentado. Las clases de almacenamiento de secretos y transporte son internas y no deben utilizarse desde plugins consumidores.
