<?php

namespace App\Services\AuditAndTraceability;

final class AuditEventCatalog
{
    /** @var list<string> Eventos de manejo registrados originalmente bajo Lotes. */
    public const MANAGEMENT_PLAN_EVENTS = [
        'plan_template_created', 'plan_template_revised', 'plan_template_published',
        'plan_template_retired', 'plan_template_activated',
        'flock_plan_assigned', 'flock_plan_inherited', 'flock_plan_revised',
    ];

    public static function moduleName(?string $logName, ?string $event): ?string
    {
        return $logName === 'lots' && in_array($event, self::MANAGEMENT_PLAN_EVENTS, true)
            ? 'management_plans' : $logName;
    }

    /** @var array<string, string> */
    private const LABELS = [
        'weighing_recorded' => 'Pesaje registrado',
        'weighing_corrected' => 'Pesaje rectificado',
        'daily_weighing_entry_added' => 'Ingreso de pesaje diario registrado',
        'daily_weighing_entry_deleted' => 'Ingreso de pesaje diario eliminado',
        'feed_consumption_recorded' => 'Consumo diario de ración registrado',
        'feed_consumption_corrected' => 'Consumo diario de ración rectificado',
        'flock_feeding_initialized' => 'Alimentación del lote inicializada',
        'flock_feeding_manual_changed' => 'Alimentación manual configurada',
        'flock_feeding_automatic_restored' => 'Alimentación automática restablecida',
        'flock_feeding_plan_revised' => 'Plan de alimentación revisado',
        'inventory_movement_recorded' => 'Movimiento de inventario registrado',
        'inventory_stock_became_negative' => 'Stock de inventario negativo',
        'shared_device_paired' => 'Dispositivo compartido vinculado',
        'shared_device_revoked' => 'Dispositivo compartido revocado',
        'user_registered' => 'Usuario registrado',
        'user_logged_out' => 'Sesión cerrada',
        'shared_pin_login' => 'Inicio de sesión en dispositivo compartido',
    ];

    public function label(?string $event, ?string $description = null): string
    {
        if (isset(self::LABELS[$event ?? ''])) {
            return self::LABELS[$event];
        }
        $description = trim(strip_tags($description ?? ''));

        return $description !== '' && $description !== $event && preg_match('/^[a-z0-9_.:-]+$/i', $description) !== 1
            ? $description : 'Actividad registrada';
    }
}
