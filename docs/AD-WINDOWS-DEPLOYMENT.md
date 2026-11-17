# Despliegue limpio del agente AD en PRTG

Este procedimiento reemplaza únicamente la identidad y configuración del agente AD de laboratorio. No elimina el bot de Telegram, los chats vinculados, las autorizaciones GLPI ni el historial de auditoría.

## Identidad nueva

Usar un identificador nuevo, no reutilizar `SVRSNGPRTG-LAB` ni `SVRSNGPRTG-LAB2`:

- AgentId: `SVRSNGPRTG-AD01`
- Nombre: `PRTG - Agente AD laboratorio`
- Controlador: `SVRDC01.Songa.local`
- Endpoint de laboratorio: `https://glpi.local/marketplace/telegram/front/ad-agent.php`

Registrar el agente desde **FML Telegram → Configuración → Agentes de Active Directory**. Copiar el secreto que GLPI muestra una sola vez. Mantener el agente en estado pendiente hasta terminar la provisión en Windows.

## Archivos que se copian al PRTG

Copiar desde la carpeta `windows` del repositorio `main`:

| Archivo del repositorio | Destino en PRTG |
|---|---|
| `windows/Agent.ps1` | `C:\SongaADAgent\scripts\Agent.ps1` |
| `windows/Install-AgentSecret.ps1` | `C:\SongaADAgent\scripts\Install-AgentSecret.ps1` |
| `windows/Test-AgentConnection.ps1` | `C:\SongaADAgent\scripts\Test-AgentConnection.ps1` |

No copiar `agent.json`: el instalador lo genera en `C:\ProgramData\SongaADAgent\agent.json`.

No reemplazar `C:\SongaADAgent\bin\SongaADAgent.exe` ni `SongaADAgent.xml`. El servicio WinSW existente conserva su identidad de ejecución y debe seguir apuntando a `C:\SongaADAgent\scripts\Agent.ps1`.

## Provisión única

1. Detener el servicio `SongaADAgent`.
2. Respaldar `C:\ProgramData\SongaADAgent\agent.json` fuera del directorio activo. No reutilizar ese archivo.
3. Ejecutar PowerShell como administrador desde `C:\SongaADAgent\scripts`:

```powershell
.\Install-AgentSecret.ps1 `
  -ApiUrl 'https://glpi.local/marketplace/telegram/front/ad-agent.php' `
  -AgentId 'SVRSNGPRTG-AD01' `
  -DomainController 'SVRDC01.Songa.local' `
  -ServiceAccount 'SONGA\svc_glpi_ad'
```

4. Cuando el instalador lo solicite, pegar el secreto nuevo de GLPI. El script lo cifra con DPAPI LocalMachine y escribe el archivo de configuración protegido.
5. En GLPI, aprobar `SVRSNGPRTG-AD01` y seleccionarlo como agente predeterminado.
6. Ejecutar una sola vez la prueba de autenticación:

```powershell
.\Test-AgentConnection.ps1
```

El resultado correcto es HTTP 400 `unsupported_action`: confirma que GLPI aceptó el HMAC y que la prueba no reclamó ni ejecutó una solicitud AD.
7. Iniciar el servicio `SongaADAgent`.

## Qué no se debe borrar ni modificar

- No borrar `C:\SongaADAgent\bin\SongaADAgent.exe` ni `SongaADAgent.xml`.
- No borrar ni recrear la cuenta de servicio `svc_glpi_ad@Songa.local`.
- No regenerar el token del bot Telegram ni la clave de cifrado Sodium de GLPI.
- No cambiar todavía el endpoint a GLPI productivo.
- Conservar el log anterior como respaldo; el nuevo agente seguirá escribiendo en `C:\ProgramData\SongaADAgent\agent.log`.

## Limpieza de registros anteriores en GLPI

Después de que las solicitudes activas asignadas a los agentes anteriores estén cerradas o canceladas, eliminar los registros antiguos `SVRSNGPRTG-LAB` y `SVRSNGPRTG-LAB2` desde la interfaz de agentes. La migración de la clave compartida antigua ahora la elimina después de una migración exitosa, por lo que una actualización posterior ya no debe recrear automáticamente `SVRSNGPRTG-LAB`.

Este procedimiento es exclusivamente para Laragon. No aplicarlo en `https://soporte.songa.com` hasta completar la validación integral en laboratorio.
