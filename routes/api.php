<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HrTransactionController;
use App\Http\Controllers\Api\LaborRuleController;
use App\Http\Controllers\Api\SalaryComponentController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\SettlementRuleController;
use App\Http\Controllers\Api\TerminationTypeController;
use App\Http\Controllers\Api\TransactionExportController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/system/public-settings',[\App\Http\Controllers\Api\SystemSettingsController::class,'publicSettings']);
Route::get('/system/branding/{id}',[\App\Http\Controllers\Api\SystemSettingsController::class,'asset']);

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureUserIsActive::class])->group(function () {
    Route::prefix('developer')->middleware(\App\Http\Middleware\AuthorizeDeveloper::class)->group(function () {
        Route::get('/settings',[\App\Http\Controllers\Api\SystemSettingsController::class,'index']);
        Route::put('/settings',[\App\Http\Controllers\Api\SystemSettingsController::class,'update']);
        Route::post('/settings/reset',[\App\Http\Controllers\Api\SystemSettingsController::class,'reset']);
        Route::get('/settings/export',[\App\Http\Controllers\Api\SystemSettingsController::class,'export']);
        Route::post('/settings/restore',[\App\Http\Controllers\Api\SystemSettingsController::class,'restore']);
        Route::get('/settings/audit',[\App\Http\Controllers\Api\SystemSettingsController::class,'audit']);
        Route::get('/health',[\App\Http\Controllers\Api\SystemSettingsController::class,'health']);
        Route::post('/branding/upload',[\App\Http\Controllers\Api\SystemSettingsController::class,'upload']);
    });
    Route::get('/users/permissions', [\App\Http\Controllers\Api\UserManagementController::class, 'permissions']);
    Route::get('/users', [\App\Http\Controllers\Api\UserManagementController::class, 'index']);
    Route::post('/users', [\App\Http\Controllers\Api\UserManagementController::class, 'store']);
    Route::put('/users/{user}', [\App\Http\Controllers\Api\UserManagementController::class, 'update']);
    Route::get('/users/{user}/audit', [\App\Http\Controllers\Api\UserManagementController::class, 'audit']);
    Route::get('/data-management', [\App\Http\Controllers\Api\DataManagementController::class, 'index']);
    Route::put('/data-management/advances/{id}', [\App\Http\Controllers\Api\DataManagementController::class, 'updateAdvance']);
    Route::get('/data-management/{kind}/{id}', [\App\Http\Controllers\Api\DataManagementController::class, 'preview']);
    Route::post('/data-management/{kind}/{id}/archive', [\App\Http\Controllers\Api\DataManagementController::class, 'archive']);
    Route::delete('/data-management/{kind}/{id}', [\App\Http\Controllers\Api\DataManagementController::class, 'destroy']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::get('/advances', [\App\Http\Controllers\Api\AdvanceController::class, 'index']);
    Route::get('/advance-reports', [\App\Http\Controllers\Api\AdvanceReportController::class, 'index']);
    Route::post('/advances', [\App\Http\Controllers\Api\AdvanceController::class, 'store']);
    Route::get('/advances/{advance}', [\App\Http\Controllers\Api\AdvanceController::class, 'show']);
    Route::post('/advances/{advance}/approve', [\App\Http\Controllers\Api\AdvanceController::class, 'approve']);
    Route::post('/advances/{advance}/movements', [\App\Http\Controllers\Api\AdvanceController::class, 'movement']);
    Route::post('/advances/{advance}/entries/{entry}/reverse', [\App\Http\Controllers\Api\AdvanceController::class, 'reverse']);
    Route::patch('/advances/{advance}/flags', [\App\Http\Controllers\Api\AdvanceController::class, 'flags']);
    Route::post('/advances/{advance}/documents', [\App\Http\Controllers\Api\AdvanceController::class, 'document']);
    Route::get('/advances/{advance}/documents/{document}', [\App\Http\Controllers\Api\AdvanceController::class, 'downloadDocument']);
    Route::get('/employees/{employee}/advances', [\App\Http\Controllers\Api\AdvanceController::class, 'employeeSummary']);
    Route::get('/settlement-finance', [\App\Http\Controllers\Api\SettlementFinanceController::class, 'index']);
    Route::get('/hr-transactions/{settlement}/finance', [\App\Http\Controllers\Api\SettlementFinanceController::class, 'show']);
    Route::put('/hr-transactions/{settlement}/advance-decision', [\App\Http\Controllers\Api\SettlementFinanceController::class, 'decision']);
    Route::post('/hr-transactions/{settlement}/financial-review', [\App\Http\Controllers\Api\SettlementFinanceController::class, 'review']);
    Route::post('/hr-transactions/{settlement}/advance-legal-review', [\App\Http\Controllers\Api\SettlementFinanceController::class, 'legal']);
    Route::post('/hr-transactions/{settlement}/post', [\App\Http\Controllers\Api\SettlementFinanceController::class, 'post']);
    Route::post('/hr-transactions/{settlement}/pay', [\App\Http\Controllers\Api\SettlementFinanceController::class, 'pay']);
    Route::post('/hr-transactions/{settlement}/financial-cancel', [\App\Http\Controllers\Api\SettlementFinanceController::class, 'cancel']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/profile', [AuthController::class, 'profile']);

    Route::get('/employees/companies', [EmployeeController::class, 'companies']);
    Route::get('/employee-imports/template', [\App\Http\Controllers\Api\EmployeeImportController::class, 'template']);
    Route::get('/employee-imports', [\App\Http\Controllers\Api\EmployeeImportController::class, 'index']);
    Route::post('/employee-imports/preview', [\App\Http\Controllers\Api\EmployeeImportController::class, 'preview']);
    Route::get('/employee-imports/{employeeImport}', [\App\Http\Controllers\Api\EmployeeImportController::class, 'show']);
    Route::post('/employee-imports/{employeeImport}/commit', [\App\Http\Controllers\Api\EmployeeImportController::class, 'commit']);
    Route::get('/employee-imports/{employeeImport}/errors', [\App\Http\Controllers\Api\EmployeeImportController::class, 'errors']);
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);

    Route::get('/contracts', [ContractController::class, 'index']);
    Route::post('/contracts', [ContractController::class, 'store']);

    Route::get('/salary-components', [SalaryComponentController::class, 'index']);
    Route::post('/salary-components', [SalaryComponentController::class, 'store']);

    Route::get('/termination-types', [TerminationTypeController::class, 'index']);
    Route::post('/termination-types', [TerminationTypeController::class, 'store']);

    Route::get('/labor-rules', [LaborRuleController::class, 'index']);
    Route::post('/labor-rules', [LaborRuleController::class, 'store']);

    Route::get('/settlement-rules', [SettlementRuleController::class, 'index']);
    Route::post('/settlement-rules', [SettlementRuleController::class, 'store']);

    Route::get('/settlements', [SettlementController::class, 'index']);
    Route::post('/settlements', [SettlementController::class, 'store']);
    Route::post('/settlements/calculate', [SettlementController::class, 'calculate']);

    Route::get('/hr-transactions', [HrTransactionController::class, 'index']);
    Route::post('/hr-transactions', [HrTransactionController::class, 'store']);
    Route::post('/hr-transactions/calculate', [HrTransactionController::class, 'calculate']);
    Route::get('/hr-transactions/{hrTransaction}', [HrTransactionController::class, 'show']);
    Route::put('/hr-transactions/{hrTransaction}', [HrTransactionController::class, 'update']);
    Route::patch('/hr-transactions/{hrTransaction}/items/{item}/adjust', [HrTransactionController::class, 'adjustItem']);
    Route::post('/hr-transactions/{hrTransaction}/items/{item}/revert-adjustment', [HrTransactionController::class, 'revertAdjustment']);
    Route::post('/hr-transactions/{hrTransaction}/submit-review', [HrTransactionController::class, 'submitReview']);
    Route::post('/hr-transactions/{hrTransaction}/approve', [HrTransactionController::class, 'approve']);
    Route::post('/hr-transactions/{hrTransaction}/reject', [HrTransactionController::class, 'reject']);
    Route::post('/hr-transactions/{hrTransaction}/request-changes', [HrTransactionController::class, 'requestChanges']);
    Route::post('/hr-transactions/{hrTransaction}/cancel', [HrTransactionController::class, 'cancel']);
    Route::post('/hr-transactions/{hrTransaction}/reopen', [HrTransactionController::class, 'reopen']);
    Route::get('/hr-transactions/{transaction}/export/excel', [TransactionExportController::class, 'excel']);
    Route::get('/hr-transactions/{transaction}/export/pdf', [TransactionExportController::class, 'pdf']);
    Route::get('/hr-transactions/{transaction}/print', [TransactionExportController::class, 'printData']);
    Route::post('/hr-transactions/{transaction}/print', [TransactionExportController::class, 'printed']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
