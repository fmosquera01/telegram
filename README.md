# FML Telegram

Plugin central de notificaciones Telegram para GLPI 11.

- **Nombre visible:** FML Telegram
- **Identificador técnico / directorio:** `telegram`
- **Repositorio:** https://github.com/fmosquera01/telegram
- **Rama de desarrollo:** `main`
- **Versión de desarrollo:** `0.2.0`
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
- Panel inicial para guardar/probar el bot, registrar destinos y definir suscripciones.
- API PHP `GlpiPlugin\\Telegram\\NotificationService::enqueue()` para encolar mensajes.
- Cola persistente, deduplicación, procesamiento por lotes, reintentos y recuperación de envíos interrumpidos.
- Productores iniciales para tickets nativos: creación, cambio de estado, cambio de prioridad y asignación de técnico.
- Envío HTTPS con validación TLS y límites de tiempo.
- Workflow de GitHub Actions para revisar sintaxis PHP.

**Estado: desarrollo incompleto. No instalar ni probar todavía.** Falta completar la administración de destinos/suscripciones e historial, ampliar y verificar eventos GLPI, ejecutar validaciones automáticas y realizar una prueba real en GLPI 11.0.11. Avisaré expresamente cuando esté listo para instalar y probar.

El trabajo se limita a este repositorio y a la rama `main`. Ningún otro plugin ni el núcleo de GLPI se modifica.

## Documentación

- [Arquitectura y contrato de integración](docs/ARCHITECTURE.md)
