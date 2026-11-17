# FML Telegram

Plugin central de notificaciones Telegram para GLPI 11.

- **Nombre visible:** FML Telegram
- **Identificador técnico / directorio:** `telegram`
- **Repositorio:** https://github.com/fmosquera01/telegram
- **Rama de desarrollo:** `main`
- **Versión de desarrollo:** `0.2.78`
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

La implementación actual incluye:

- Instalación de tablas propias y tarea automática de entrega.
- Almacenamiento cifrado del token mediante Sodium y clave privada fuera de la base de datos.
- Panel de administración para guardar/probar el bot, crear/editar/activar destinos, asociarlos opcionalmente a usuarios GLPI mediante el selector nativo de usuarios (búsqueda por usuario, nombre, apellido y nombre completo, como en tickets), autocompletar nombre y alias desde la cuenta GLPI seleccionada, o registrar manualmente destinos sin asociación, y gestionar suscripciones con selectores guiados de módulo y evento, conservando identificadores personalizados para plugins externos.
- API PHP `GlpiPlugin\Telegram\NotificationService::enqueue()` para encolar mensajes por alias o por usuario GLPI vinculado.
- Cola persistente, deduplicación, procesamiento por lotes, reintentos por ciclo, recuperación de envíos interrumpidos e historial por intento.
- Productores nativos para tickets: creación, asignación de técnico o grupo, cambio de estado, prioridad y configuración de SLA. La asignación a un técnico se enruta a su destino privado vinculado y, en paralelo, a los grupos suscritos; también recibe inventarios exitosos y genera alertas programadas de SLA próximo a vencer o vencido.
- Envío HTTPS con validación TLS y límites de tiempo.
- Workflow de GitHub Actions para revisar sintaxis PHP y ejecutar pruebas de contrato de la API.
- Integración controlada de Active Directory mediante agentes Windows autenticados con HMAC individual, registro/aprobación/revocación en GLPI y asignación explícita de solicitudes por identificador de agente.

**Estado: versión de pruebas 0.2.78.** El subsistema AD incluye solicitudes, auditoría, registro/aprobación de agentes, secreto HMAC individual cifrado, endpoint autenticado y cola de operaciones. La versión 0.2.78 añade `/bloqueados`, paginación de 10 cuentas y selección individual; solo muestra usuarios habilitados y bloqueados. El agente vuelve a comprobar que la cuenta siga habilitada antes de desbloquearla. La recuperación del secreto ahora informa causas técnicas sin exponer credenciales y dispone de una prueba automatizada de cifrado/descifrado. La interfaz administrativa se separa en Inicio, Notificaciones, Cola de salida y Active Directory. Esta versión debe validarse primero en el GLPI de laboratorio; no desplegar en producción hasta completar las pruebas funcionales y de integración.

El trabajo se limita a este repositorio y a la rama `main`. Ningún otro plugin ni el núcleo de GLPI se modifica. El cron `send` permanece dedicado exclusivamente a notificaciones; la recepción interactiva de Telegram y la comunicación con el agente AD operan por procesos independientes y no dependen del ciclo de un minuto de la cola de notificaciones.

## Documentación

- [Arquitectura y contrato de integración](docs/ARCHITECTURE.md)
- [API para plugins consumidores](docs/API.md)
- [Guía preliminar de instalación y operación](docs/INSTALL.md)

### Prueba segura del agente Windows AD

Antes de iniciar `Agent.ps1`, ejecutar en el servidor del agente, como administrador:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force
& "C:\SongaADAgentLab\Test-AgentConnection.ps1"
```

La prueba descifra la configuración con DPAPI LocalMachine y envía una petición HMAC real usando `curl.exe` con validación TLS normal. Usa intencionalmente la acción no soportada `hmac_test`: HTTP 400 `unsupported_action` confirma la autenticación sin reclamar ni ejecutar solicitudes de Active Directory.


## Pruebas de la versión 0.2.78

En el entorno de desarrollo, ejecutar desde la raíz del repositorio:

```bash
php tests/run.php
php tests/ad-request.php
php tests/agent-auth.php
php tests/agent-secret-store.php
php tests/ad-agent-queue.php
```

La prueba `agent-secret-store.php` crea un directorio temporal aislado y verifica que un secreto individual se cifra, se recupera sin alteraciones y distingue la ausencia de la clave de cifrado. No accede a la base de datos real ni modifica secretos de agentes existentes.

En GLPI de laboratorio, el panel **Active Directory** muestra el diagnóstico del secreto por agente. Un estado **Recuperable** significa que GLPI puede leer y descifrar el secreto almacenado; no significa por sí solo que el secreto instalado en Windows coincida. La comprobación final es la prueba HMAC segura del agente, que no reclama ni ejecuta operaciones AD.
