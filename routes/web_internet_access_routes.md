# Routes to add to routes/web.php

Insert the block below **inside** the `Route::middleware(['auth'])->prefix('admin')->name('admin.')` group,
near the other `permission:manage-settings` groups (after provisioning-licenses routes):

```php
use App\Http\Controllers\Admin\InternetAccessLevelController;

// ── Internet Access Levels (Settings → Provisioning) ─────────────────────────
Route::middleware('permission:manage-settings')
    ->prefix('settings/internet-access-levels')
    ->name('settings.internet-access-levels.')
    ->group(function () {
        Route::get('/',                                  [InternetAccessLevelController::class, 'index'])   ->name('index');
        Route::post('/',                                 [InternetAccessLevelController::class, 'store'])   ->name('store');
        Route::put('/{internetAccessLevel}',             [InternetAccessLevelController::class, 'update'])  ->name('update');
        Route::delete('/{internetAccessLevel}',          [InternetAccessLevelController::class, 'destroy']) ->name('destroy');
        Route::get('/azure-groups/search',               [InternetAccessLevelController::class, 'searchAzureGroups'])->name('azure-groups.search');
    });
```

## Named routes reference:

| Route name | URL | Method |
|---|---|---|
| `admin.settings.internet-access-levels.index`   | `/admin/settings/internet-access-levels`      | GET    |
| `admin.settings.internet-access-levels.store`   | `/admin/settings/internet-access-levels`      | POST   |
| `admin.settings.internet-access-levels.update`  | `/admin/settings/internet-access-levels/{id}` | PUT    |
| `admin.settings.internet-access-levels.destroy` | `/admin/settings/internet-access-levels/{id}` | DELETE |
| `admin.settings.internet-access-levels.azure-groups.search` | `/admin/settings/internet-access-levels/azure-groups/search` | GET |
