# FML Telegram

Plugin central de notificaciones Telegram para GLPI 11.

- **Nombre visible:** FML Telegram
- **Identificador técnico / directorio:** `telegram`
- **Repositorio:** https://github.com/fmosquera01/telegram
- **Rama de desarrollo:** `main`
- **Versión de desarrollo:** `0.2.23`
- **GLPI objetivo:** 11.0.11
- **PHP objetivo:** 8.2 o superior, sujeto a validación final con GLPI 11.0.11

## Objetivo

Centralizar el envío de notificaciones Telegram originadas tanto por GLPI nativo como por cualquier plugin instalado. Debe existir un único bot y una única configuración de transporte, sin duplicar tokens, conexiones HTTP, colas ni lógica de reintentos en cada plugin consumidor.

## Alcance funcional

1. Configuración segura del bot y prueba de conectividad.
2. Gestión centralizada de destinos (usuarios, chats y grupos).
3. Suscripciones por origen y tipo de evento.
4. API pública para que plugins soliciten notificaciones.
5. Cola persistente de salida, reintentos y control de duplicados.
6. Historial de entregas, errores y auditoría.
7. Integración gradual con eventos nativos de GLPI.
8. Compatibilidad con GLPI 11.0.11 y PHP soportado por esa versión.

## Principios

- No modificar archivos ni tablas del núcleo de GLPI.
- No almacenar el token en texto plano en la base de datos.
- No enviar HTTP a Telegram dentro de operaciones críticas de GLPI; los productores encolan y una tarea programada realiza la entrega.
- Si Telegram falla, la operación de GLPI no debe fallar y la notificación debe permanecer pendiente para reintento.
- Un plugin consumidor no necesita conocer el token ni llamar directamente a la API de Telegram.
- La desinstalación no debe borrar silenciosamente el historial de notificaciones.
- Todas las escrituras se realizarán en `main`, incrementando la versión del plugin en cada entrega.

## Implementación actual

La primera base funcional está en desarrollo e incluye:

- Instalación de tablas propias y tarea automática de entrega.
- Almacenamiento cifrado del token mediante Sodium y clave privada fuera de la base de datos.
- Panel de administración para guardar/probar el bot, crear/editar/activar destinos, asociarlos opcionalmente a usuarios GLPI y gestionar suscripciones.
- API PHP `GlpiPlugin\Telegram\NotificationService::enqueue()` para encolar mensajes por alias o por usuario GLPI vinculado.
- Cola persistente, deduplicación, procesamiento por lotes, reintentos por ciclo, recuperación de envíos interrumpidos e historial por intento.
- Productores nativos para tickets: creación, asignación de técnico o grupo, cambio de estado, prioridad y configuración de SLA; también recibe inventarios exitosos y genera alertas programadas de SLA próximo a vencer o vencido.
- Envío HTTPS con validación TLS y límites de tiempo.
- Workflow de GitHub Actions para revisar sintaxis PHP y ejecutar pruebas de contrato de la API.

**Estado: listo para instalar y probar en un ambiente de pruebas.** GitHub Actions validó la instalación y activación sobre GLPI 11.0.11 con MariaDB 10.11, creación de tablas, registro de tareas cron, menú, API de cola, eventos nativos y escaneo SLA. También pasan las pruebas PHP 8.2/8.3 y los contratos de API. La conexión real a Telegram requiere configurar el token del bot y probar la salida HTTPS desde el servidor.

El trabajo se limita a este repositorio y a la rama `main`. Ningún otro plugin ni el núcleo de GLPI se modifica.

## Documentación

- [Arquitectura y contrato de integración](docs/ARCHITECTURE.md)
- [API para plugins consumidores](docs/API.md)
- [Guía preliminar de instalación y operación](docs/INSTALL.md)
