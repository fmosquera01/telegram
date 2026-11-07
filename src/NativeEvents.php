<?php

namespace GlpiPlugin\Telegram;

use CommonDBTM;
use CommonITILActor;
use Ticket;
use Ticket_User;
use Group_Ticket;
use Group;
use User;
use Throwable;

final class NativeEvents
{
    /**
     * GLPI 11.0.11 calls POST_INVENTORY only after a successful inventory import.
     */
    public static function inventoryReceived(?object $inventory): void
    {
        if ($inventory === null || empty($inventory->deviceid)) {
            return;
        }

        $deviceId = trim((string) $inventory->deviceid);
        $itemType = trim((string) ($inventory->itemtype ?? 'Computer'));
        $agentVersion = trim((string) ($inventory->version ?? ''));

        if ($deviceId === '' || strlen($deviceId) > 180) {
            return;
        }

        $minuteBucket = date('YmdHi');
        $message = "GLPI recibió e importó correctamente un inventario.\n"
            . "Identificador del equipo: {$deviceId}\n"
            . "Tipo de activo: {$itemType}";
        if ($agentVersion !== '') {
            $message .= "\nVersión del agente: {$agentVersion}";
        }

        NotificationService::enqueue([
            'source' => 'glpi',
            'event' => 'inventory.received',
            'subject' => 'Inventario actualizado: ' . mb_substr($deviceId, 0, 140, 'UTF-8'),
            'message' => $message,
            'deduplication_key' => 'inventory.received:' . hash('sha256', $deviceId . ':' . $minuteBucket),
        ]);
    }

    public static function ticketAdded(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket || empty($item->fields['id'])) {
            return;
        }

        $id = (int) $item->fields['id'];
        $title = trim((string) ($item->fields['name'] ?? ''));
        $message = "Se creó el ticket #{$id}";
        if ($title !== '') {
            $message .= "\nAsunto: " . $title;
        }
        $message .= "\nPrioridad: " . (int) ($item->fields['priority'] ?? 0);

        NotificationService::enqueue([
            'source' => 'glpi',
            'event' => 'ticket.created',
            'subject' => "Nuevo ticket #{$id}",
            'message' => $message,
            'deduplication_key' => "ticket.created:{$id}",
        ]);
    }

    public static function ticketUpdated(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket || empty($item->fields['id']) || !is_array($item->updates ?? null)) {
            return;
        }

        $id = (int) $item->fields['id'];
        $dateMod = (string) ($item->fields['date_mod'] ?? date('Y-m-d H:i:s'));

        if (in_array('status', $item->updates, true)) {
            $oldStatus = (int) ($item->oldvalues['status'] ?? 0);
            $newStatus = (int) ($item->fields['status'] ?? 0);
            NotificationService::enqueue([
                'source' => 'glpi',
                'event' => 'ticket.status_changed',
                'subject' => "Cambio de estado del ticket #{$id}",
                'message' => "El ticket #{$id} cambió de estado.\nEstado anterior: {$oldStatus}\nNuevo estado: {$newStatus}",
                'deduplication_key' => "ticket.status:{$id}:{$dateMod}:{$newStatus}",
            ]);
        }

        if (in_array('priority', $item->updates, true)) {
            $oldPriority = (int) ($item->oldvalues['priority'] ?? 0);
            $newPriority = (int) ($item->fields['priority'] ?? 0);
            NotificationService::enqueue([
                'source' => 'glpi',
                'event' => 'ticket.priority_changed',
                'subject' => "Cambio de prioridad del ticket #{$id}",
                'message' => "El ticket #{$id} cambió de prioridad.\nPrioridad anterior: {$oldPriority}\nNueva prioridad: {$newPriority}",
                'deduplication_key' => "ticket.priority:{$id}:{$dateMod}:{$newPriority}",
            ]);
        }

        $slaFields = ['slas_id_tto', 'slas_id_ttr', 'time_to_own', 'time_to_resolve'];
        if (array_intersect($slaFields, $item->updates) !== []) {
            $oldTto = (int) ($item->oldvalues['slas_id_tto'] ?? 0);
            $newTto = (int) ($item->fields['slas_id_tto'] ?? 0);
            $oldTtr = (int) ($item->oldvalues['slas_id_ttr'] ?? 0);
            $newTtr = (int) ($item->fields['slas_id_ttr'] ?? 0);
            $oldOwnDeadline = (string) ($item->oldvalues['time_to_own'] ?? '');
            $newOwnDeadline = (string) ($item->fields['time_to_own'] ?? '');
            $oldResolveDeadline = (string) ($item->oldvalues['time_to_resolve'] ?? '');
            $newResolveDeadline = (string) ($item->fields['time_to_resolve'] ?? '');

            $slaFingerprint = hash('sha256', implode('|', [
                $newTto,
                $newTtr,
                $newOwnDeadline,
                $newResolveDeadline,
            ]));

            NotificationService::enqueue([
                'source' => 'glpi',
                'event' => 'ticket.sla_changed',
                'subject' => "Cambio de SLA del ticket #{$id}",
                'message' => "Se modificó la configuración de SLA del ticket #{$id}.\n"
                    . "SLA de atención: {$oldTto} → {$newTto}\n"
                    . "SLA de resolución: {$oldTtr} → {$newTtr}\n"
                    . "Fecha límite de atención: " . ($oldOwnDeadline ?: 'sin fecha') . " → " . ($newOwnDeadline ?: 'sin fecha') . "\n"
                    . "Fecha límite de resolución: " . ($oldResolveDeadline ?: 'sin fecha') . " → " . ($newResolveDeadline ?: 'sin fecha'),
                'deduplication_key' => "ticket.sla:{$id}:{$dateMod}:{$slaFingerprint}",
            ]);
        }
    }

    public static function ticketGroupAdded(CommonDBTM $item): void
    {
        if (!$item instanceof Group_Ticket || empty($item->fields['tickets_id'])
            || (int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN
        ) {
            return;
        }

        $ticketId = (int) $item->fields['tickets_id'];
        $groupId = (int) ($item->fields['groups_id'] ?? 0);
        $groupName = "grupo #{$groupId}";

        if ($groupId > 0) {
            try {
                $group = new Group();
                if ($group->getFromDB($groupId)) {
                    $groupName = (string) ($group->fields['name'] ?? $groupName);
                }
            } catch (Throwable $exception) {
                // Notification enrichment must never interrupt ticket assignment.
            }
        }

        $relationId = (int) ($item->fields['id'] ?? 0);
        NotificationService::enqueue([
            'source' => 'glpi',
            'event' => 'ticket.assigned',
            'subject' => "Asignación del ticket #{$ticketId}",
            'message' => "Se asignó el ticket #{$ticketId} al grupo: {$groupName}",
            'deduplication_key' => "ticket.group_assigned:{$ticketId}:{$relationId}:{$groupId}",
        ]);
    }

    public static function ticketActorAdded(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket_User || empty($item->fields['tickets_id'])) {
            return;
        }

        // Only assigned actors generate this event.
        if ((int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
            return;
        }

        $ticketId = (int) $item->fields['tickets_id'];
        $userId = (int) ($item->fields['users_id'] ?? 0);
        $userName = "usuario #{$userId}";

        if ($userId > 0) {
            try {
                $user = new User();
                if ($user->getFromDB($userId)) {
                    $userName = trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? ''));
                    if ($userName === '') {
                        $userName = (string) ($user->fields['name'] ?? "usuario #{$userId}");
                    }
                }
            } catch (Throwable $exception) {
                // Notification enrichment must never interrupt ticket assignment.
            }
        }

        $dedup = (int) ($item->fields['id'] ?? 0);
        NotificationService::enqueue([
            'source' => 'glpi',
            'event' => 'ticket.assigned',
            'subject' => "Asignación del ticket #{$ticketId}",
            'message' => "Se asignó el ticket #{$ticketId} al técnico: {$userName}",
            'recipients' => $userId > 0 ? ['user:' . $userId] : [],
            'include_subscriptions' => true,
            'deduplication_key' => "ticket.assigned:{$ticketId}:{$dedup}:{$userId}",
        ]);
    }
}
