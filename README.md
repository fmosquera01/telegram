# FML Telegram

Plugin central de notificaciones Telegram para GLPI 11.

- **Nombre visible:** FML Telegram
- **Identificador técnico / directorio:** `telegram`
- **Repositorio:** https://github.com/fmosquera01/telegram
- **Rama de desarrollo:** `main`
- **Versión inicial de diseño:** `0.1.0`
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

## Estado

Este repositorio comienza con la especificación y la estructura de diseño. **No es todavía una versión instalable ni funcional.** La implementación y validación se realizarán antes de solicitar cualquier prueba en el ambiente del usuario.

## Documentación

- [Arquitectura y contrato de integración](docs/ARCHITECTURE.md)
