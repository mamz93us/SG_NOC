<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use Illuminate\Support\Facades\Cache;

/**
 * The home portal's announcement cache, in one place.
 *
 * Announcements are cached per audience rather than per user, because the
 * audience triple is what the query varies on: a thousand employees in one
 * branch share one entry instead of making a thousand.
 *
 * That leaves the key format and the flush as a matched pair, and there are
 * now three callers — the page that reads it, the admin form that writes, and
 * the Oracle sync. Held apart, they drift, and the failure is a correction to
 * an urgent notice still showing the old text for five minutes, which is
 * exactly when nobody is willing to wait.
 */
class AnnouncementCache
{
    public const TTL_MINUTES = 5;

    public static function key(?int $branchId, ?int $departmentId): string
    {
        return sprintf('home.announcements.%s.%s', $branchId ?? 'nb', $departmentId ?? 'nd');
    }

    /**
     * Drop every audience's entry.
     *
     * Enumerated rather than tagged: the cache store here is the database, and
     * tags are not available on it.
     */
    public static function flush(): void
    {
        try {
            Cache::forget(self::key(null, null));

            $departmentIds = Department::pluck('id');

            foreach ($departmentIds as $departmentId) {
                Cache::forget(self::key(null, (int) $departmentId));
            }

            foreach (Branch::pluck('id') as $branchId) {
                Cache::forget(self::key((int) $branchId, null));

                foreach ($departmentIds as $departmentId) {
                    Cache::forget(self::key((int) $branchId, (int) $departmentId));
                }
            }
        } catch (\Throwable) {
            // A cache store hiccup must not fail the save or the sync; every
            // entry expires within five minutes regardless.
        }
    }
}
