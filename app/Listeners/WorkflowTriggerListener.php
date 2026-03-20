<?php

namespace App\Listeners;

use App\Events\EmployeeCreated;
use App\Events\HostStatusChanged;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\Log;

class WorkflowTriggerListener
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * Map event classes to the trigger_event string stored on templates.
     */
    private static array $eventMap = [
        EmployeeCreated::class   => 'employee.created',
        HostStatusChanged::class => 'host.down',  // only fires for 'down' status
    ];

    public function handle(object $event): void
    {
        $eventClass = get_class($event);
        $triggerKey = static::$eventMap[$eventClass] ?? null;

        if (! $triggerKey) {
            return;
        }

        // Special case: HostStatusChanged only triggers on 'down'
        if ($event instanceof HostStatusChanged && $event->newStatus !== 'down') {
            return;
        }

        $template = WorkflowTemplate::where('trigger_event', $triggerKey)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return;
        }

        try {
            $payload = $this->extractPayload($event);

            $this->engine->createRequest(
                type:        $template->type_slug,
                payload:     $payload,
                branchId:    $payload['branch_id'] ?? null,
                requestedBy: null, // system-triggered
                title:       "[Auto] {$template->display_name}",
                description: "Automatically triggered by: {$triggerKey}",
            );

            Log::info("[WorkflowTriggerListener] Started '{$template->display_name}' for trigger '{$triggerKey}'");
        } catch (\Throwable $e) {
            Log::error("[WorkflowTriggerListener] Failed to start workflow: {$e->getMessage()}");
        }
    }

    private function extractPayload(object $event): array
    {
        if ($event instanceof EmployeeCreated) {
            return [
                'employee_id' => $event->employee->id,
                'name'        => $event->employee->full_name ?? $event->employee->name,
                'branch_id'   => $event->employee->branch_id,
                'department'  => $event->employee->department ?? null,
            ];
        }

        if ($event instanceof HostStatusChanged) {
            return [
                'host_id'  => $event->host->id,
                'ip'       => $event->host->ip,
                'name'     => $event->host->hostname ?? $event->host->ip,
                'status'   => $event->newStatus,
            ];
        }

        return [];
    }
}
