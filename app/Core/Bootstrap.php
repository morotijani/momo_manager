<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AuthController;
use App\Controllers\BranchController;
use App\Controllers\DashboardController;
use App\Controllers\ShiftController;
use App\Controllers\TransactionController;
use App\Controllers\VendorController;

final class Bootstrap
{
    public static function init(string $rootPath): void
    {
        require_once $rootPath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
        Autoloader::register($rootPath);
        Config::load($rootPath);
        Database::init($rootPath);
    }

    public static function createRouter(): Router
    {
        $router = new Router();

        // Auth & Multi-Owner Onboarding
        $router->get('/api/auth/me', [AuthController::class, 'me']);
        $router->post('/api/auth/register', [AuthController::class, 'register']);
        $router->post('/api/auth/login', [AuthController::class, 'login']);
        $router->post('/api/auth/logout', [AuthController::class, 'logout']);

        // Live Dashboard (Owner Command Center & Vendor Workspace)
        $router->get('/api/dashboard', [DashboardController::class, 'index']);

        // Branches & SIM Wallets + Float Adjustments
        $router->get('/api/branches', [BranchController::class, 'index']);
        $router->post('/api/branches', [BranchController::class, 'store']);
        $router->put('/api/branches/{id}', [BranchController::class, 'update']);
        $router->post('/api/branches/{id}/float', [BranchController::class, 'adjustFloat']);

        // Vendors / Employees
        $router->get('/api/vendors', [VendorController::class, 'index']);
        $router->post('/api/vendors', [VendorController::class, 'store']);
        $router->put('/api/vendors/{id}', [VendorController::class, 'update']);

        // Shifts & End-of-Day Reconciliation
        $router->get('/api/shifts', [ShiftController::class, 'index']);
        $router->post('/api/shifts/open', [ShiftController::class, 'open']);
        $router->post('/api/shifts/{id}/close', [ShiftController::class, 'close']);

        // Transactions, Telco SMS Parser & Immutable Audit Void Workflow
        $router->get('/api/transactions', [TransactionController::class, 'index']);
        $router->post('/api/transactions/parse-sms', [TransactionController::class, 'parseSms']);
        $router->post('/api/transactions', [TransactionController::class, 'store']);
        $router->post('/api/transactions/{id}/void-request', [TransactionController::class, 'requestVoid']);
        $router->post('/api/transactions/{id}/void-resolve', [TransactionController::class, 'resolveVoid']);

        return $router;
    }
}
