<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class AdminPaletteService
{
    /**
     * Static items rendered into the page on first paint so the palette
     * has results before any AJAX fires. Filtered by the current user's
     * permissions and to routes that are actually registered.
     *
     * @return array<int, array{id:string,label:string,url:string,icon:string,group:string,type:string}>
     */
    public function staticItems(): array
    {
        $user  = Auth::user();
        $items = [];
        $idx   = 0;

        foreach (config('admin_navigation', []) as $group) {
            foreach (($group['items'] ?? []) as $item) {
                $perm = $item['permission'] ?? null;
                if ($perm && $user && ! $user->can($perm)) {
                    continue;
                }
                if (! Route::has($item['route'])) {
                    continue;
                }
                $items[] = [
                    'id'    => 'nav-' . $idx++,
                    'label' => $item['label'],
                    'url'   => route($item['route']),
                    'icon'  => $item['icon'] ?? 'bi-arrow-right',
                    'group' => $group['label'],
                    'type'  => 'nav',
                ];
            }
        }

        // Quick actions — small, role-aware
        $actions = [];
        if ($user?->can('manage-contacts') && Route::has('admin.contacts.create')) {
            $actions[] = [
                'label' => 'New Contact',
                'url'   => route('admin.contacts.create'),
                'icon'  => 'bi-person-plus',
                'group' => 'Quick Action',
            ];
        }
        if ($user?->can('manage-workflows') && Route::has('admin.workflows.create')) {
            $actions[] = [
                'label' => 'New Workflow Request',
                'url'   => route('admin.workflows.create'),
                'icon'  => 'bi-send-plus',
                'group' => 'Quick Action',
            ];
        }
        if ($user?->can('manage-incidents') && Route::has('admin.noc.incidents.create')) {
            $actions[] = [
                'label' => 'New Incident',
                'url'   => route('admin.noc.incidents.create'),
                'icon'  => 'bi-exclamation-triangle',
                'group' => 'Quick Action',
            ];
        }

        foreach ($actions as $a) {
            $items[] = [
                'id'    => 'action-' . $idx++,
                'label' => $a['label'],
                'url'   => $a['url'],
                'icon'  => $a['icon'],
                'group' => $a['group'],
                'type'  => 'action',
            ];
        }

        return $items;
    }
}
