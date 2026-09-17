<?php

namespace App\Services\Itam;

use App\Models\WorkflowRequest;

/**
 * Which assets are in a scrap request still waiting for approval.
 *
 * A scrap request lists its assets in the workflow payload, not in a column,
 * and the store action writes the ids as the form sent them (strings), so
 * they are compared as strings.
 */
class PendingScrapRequests
{
    /**
     * @param  iterable<int>  $deviceIds
     * @return array<int, int> device id => workflow request id
     */
    public function forDevices(iterable $deviceIds): array
    {
        $wanted = [];

        foreach ($deviceIds as $id) {
            $wanted[(string) $id] = (int) $id;
        }

        if ($wanted === []) {
            return [];
        }

        $pending = [];

        WorkflowRequest::query()
            ->where('type', 'asset_scrap')
            ->where('status', 'pending')
            ->orderBy('id')
            ->get(['id', 'payload'])
            ->each(function (WorkflowRequest $request) use ($wanted, &$pending) {
                foreach ((array) ($request->payload['device_ids'] ?? []) as $id) {
                    if (isset($wanted[(string) $id]) && ! isset($pending[$wanted[(string) $id]])) {
                        $pending[$wanted[(string) $id]] = (int) $request->id;
                    }
                }
            });

        return $pending;
    }

    public function forDevice(int $deviceId): ?int
    {
        return $this->forDevices([$deviceId])[$deviceId] ?? null;
    }
}
