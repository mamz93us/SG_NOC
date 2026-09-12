<?php

namespace App\Observers;

use App\Support\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

/**
 * One observer for every model.
 *
 * Registered against every class in app/Models at boot (see
 * AppServiceProvider::registerAuditing), minus config('audit.exclude'). That
 * replaces the 22 near-identical hand-written observers this codebase had
 * accumulated — and, more to the point, covers the other ~180 models that had
 * no audit trail at all.
 *
 * Restores and soft-deletes are separate actions on purpose: "deleted" and
 * "restored" are different facts, and a soft delete that reads as a hard one
 * sends whoever is reading the log looking for a row that is still there.
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        Auditor::record('created', $model, Auditor::snapshot($model));
    }

    public function updated(Model $model): void
    {
        // A model that was just soft-deleted fires `updated` as well; skip it so
        // the change isn't recorded twice under two different actions.
        if ($this->isSoftDeleting($model)) {
            return;
        }

        $diff = Auditor::diff($model);

        // Nothing audit-worthy changed (a timestamp-only touch, a poll stamping
        // last_seen_at). Writing a row here would bury the real edits.
        if ($diff === null) {
            return;
        }

        Auditor::record('updated', $model, $diff);
    }

    public function deleted(Model $model): void
    {
        $action = $this->usesSoftDeletes($model) ? 'trashed' : 'deleted';

        Auditor::record($action, $model, Auditor::snapshot($model));
    }

    public function forceDeleted(Model $model): void
    {
        Auditor::record('deleted', $model, Auditor::snapshot($model));
    }

    public function restored(Model $model): void
    {
        Auditor::record('restored', $model, null);
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return method_exists($model, 'trashed');
    }

    private function isSoftDeleting(Model $model): bool
    {
        if (! $this->usesSoftDeletes($model)) {
            return false;
        }

        $column = method_exists($model, 'getDeletedAtColumn')
            ? $model->getDeletedAtColumn()
            : 'deleted_at';

        return array_key_exists($column, $model->getChanges());
    }
}
