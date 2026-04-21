<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vendor\ImportKit\Http\Controllers\EmployeeImportTestController;

Route::prefix('import-kit/test')->group(static function (): void {
    Route::post('employee/preview', [EmployeeImportTestController::class, 'preview'])
        ->name('import-kit.test.employee.preview');
    Route::post('employee/submit', [EmployeeImportTestController::class, 'submit'])
        ->name('import-kit.test.employee.submit');
    Route::get('employee/preview-fixture', [EmployeeImportTestController::class, 'previewFixture'])
        ->name('import-kit.test.employee.preview-fixture');
});
