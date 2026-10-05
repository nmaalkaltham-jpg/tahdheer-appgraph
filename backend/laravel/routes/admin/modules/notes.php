<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\AdminNoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Notes
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::prefix('notes')->group(function () {
    Route::get('/', [AdminNoteController::class, 'index']);
    Route::middleware(AdminPermission::NotesWrite->middleware())->group(function () {
        Route::post('/', [AdminNoteController::class, 'store']);
        Route::patch('/{id}', [AdminNoteController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [AdminNoteController::class, 'destroy'])->whereNumber('id');
    });
});

/*
 * المراجعات. القراءة والقرار بصلاحية واحدة: من يرى الطابور هو
 * من يفرغه، ولا معنى لعرضٍ بلا قدرة على الاعتماد أو الرفض.
 */
