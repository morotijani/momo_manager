<?php

declare(strict_types=1);

/**
 * Comprehensive Automated Integration & Ledger Test Suite for MoMo Manager
 * Run with: c:\xampp\php\php.exe tests/integration_test.php
 */

use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpResponseException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Services\SmsParserService;

$rootPath = dirname(__DIR__);
require_once $rootPath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Bootstrap.php';
Bootstrap::init($rootPath);

// Use isolated in-memory SQLite database so tests run hermetically and verify DemoSeeder + all endpoints
Config::set('DB_DRIVER', 'sqlite');
Config::set('DB_SQLITE_PATH', ':memory:');
Config::set('SEED_DEMO_DATA', 'true');
Database::reset();
Response::$captureMode = true;

$router = Bootstrap::createRouter();
$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

function callApi(string $method, string $path, array $body = [], array $query = [], string $token = '', string $csrf = ''): array
{
    global $router;
    $headers = ['accept' => 'application/json'];
    if ($token !== '') {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    if ($csrf !== '') {
        $headers['x-csrf-token'] = $csrf;
    }
    $req = new Request($method, $path, $query, $body, $headers, '127.0.0.1');
    try {
        $router->dispatch($req);
        return ['status' => 200, 'body' => []];
    } catch (HttpResponseException $e) {
        return ['status' => $e->status, 'body' => $e->payload];
    }
}

echo "=== MoMo Manager End-to-End Verification Suite ===\n\n";

// 1. Boot & Demo Seeder Check
echo "1. Boot & Demo Seeder (Move Money Enterprise)\n";
$me = callApi('GET', '/api/auth/me');
assertTrue($me['status'] === 200 && $me['body']['ok'] === true, 'GET /api/auth/me returns 200 OK');
assertTrue(count($me['body']['demo_accounts']) === 4, 'Demo seeder created Boss + 3 Branch Vendors');
$csrf = $me['body']['csrf_token'];

// 2. CSRF Enforcement on Session Endpoints
echo "\n2. Security & CSRF Protection\n";
$noCsrf = callApi('POST', '/api/auth/login', ['identifier' => '0244000001', 'password' => 'Password@123']);
assertTrue($noCsrf['status'] === 419, 'Blocks POST without CSRF token when no Bearer token provided (HTTP 419)');

// 3. Login as Vendor (Ama Boateng - Madina Branch)
echo "\n3. Vendor Login & Two-Bucket Ledger Math (Deposit, Transfer, Withdrawal)\n";
$vendorLogin = callApi('POST', '/api/auth/login', [
    'identifier' => '0244000002',
    'password' => 'Password@123',
], [], '', $csrf);
assertTrue($vendorLogin['status'] === 200, 'Vendor Ama Boateng logged in successfully');
$vendorToken = $vendorLogin['body']['token'];
Security::logoutSession(); // Use stateless Bearer token for crisp multi-actor testing

$vDash0 = callApi('GET', '/api/dashboard', [], [], $vendorToken);
$branch0 = $vDash0['body']['branches'][0];
$startCash = (float) $branch0['cash_balance'];
$mtnBefore = 0.0;
foreach ($branch0['wallets'] as $w) {
    if ($w['network'] === 'MTN') {
        $mtnBefore = (float) $w['ecash_balance'];
    }
}
assertTrue($branch0['name'] === 'Madina Zongo Junction', 'Vendor sees only their assigned branch (Madina)');

// 3a. Deposit: +500 Cash, -500 MTN E-Cash
$depRes = callApi('POST', '/api/transactions', [
    'tx_type' => 'DEPOSIT',
    'network' => 'MTN',
    'customer_phone' => '0245112233',
    'customer_name' => 'Kwadwo Nkansah',
    'amount' => 500.00,
    'telco_tx_id' => 'MTN-DEP-99001',
    'reference' => 'Personal savings',
], [], $vendorToken);
assertTrue($depRes['status'] === 201, 'Deposit recorded (HTTP 201)');
assertTrue(abs($depRes['body']['cash_balance'] - ($startCash + 500.0)) < 0.001, 'Deposit increased Physical Cash by +500.00');
assertTrue(abs($depRes['body']['ecash_balance'] - ($mtnBefore - 500.0)) < 0.001, 'Deposit decreased MTN E-Cash by -500.00');

// 3b. Duplicate Telco Transaction ID Guard
$dupRes = callApi('POST', '/api/transactions', [
    'tx_type' => 'DEPOSIT',
    'network' => 'MTN',
    'customer_phone' => '0245112233',
    'amount' => 500.00,
    'telco_tx_id' => 'MTN-DEP-99001',
], [], $vendorToken);
assertTrue($dupRes['status'] === 422, 'Duplicate Telco SMS Transaction ID blocked (HTTP 422)');

// 3c. Transfer: +305 Cash (300 principal + 5 OTC fee), -300 MTN E-Cash
$trfRes = callApi('POST', '/api/transactions', [
    'tx_type' => 'TRANSFER',
    'network' => 'MTN',
    'receiver_network' => 'TELECEL',
    'customer_phone' => '0208112233',
    'customer_name' => 'Receiver Mensah',
    'sender_name' => 'Walk-in Sender',
    'sender_phone' => '0549001122',
    'amount' => 300.00,
    'fee_charged' => 5.00,
    'telco_tx_id' => 'MTN-TRF-99002',
    'reference' => 'Remittance',
], [], $vendorToken);
assertTrue($trfRes['status'] === 201, 'Cross-network Transfer with OTC fee recorded (HTTP 201)');
assertTrue(abs($trfRes['body']['cash_balance'] - ($startCash + 500.0 + 305.0)) < 0.001, 'Transfer increased Physical Cash by +(300 + 5 fee)');
assertTrue(abs($trfRes['body']['ecash_balance'] - ($mtnBefore - 500.0 - 300.0)) < 0.001, 'Transfer decreased MTN E-Cash by -300.00');

// 3d. Withdrawal without "Allow Cash Out" confirmation must fail
$wdFail = callApi('POST', '/api/transactions', [
    'tx_type' => 'WITHDRAWAL',
    'network' => 'MTN',
    'customer_phone' => '0551234567',
    'amount' => 200.00,
    'cashout_allowed_confirmed' => false,
    'telco_tx_id' => 'MTN-WD-99003',
], [], $vendorToken);
assertTrue($wdFail['status'] === 422, 'Withdrawal rejected if Allow Cash-Out + PIN is not confirmed');

// 3e. Withdrawal with confirmation: -200 Cash, +200 MTN E-Cash
$wdOk = callApi('POST', '/api/transactions', [
    'tx_type' => 'WITHDRAWAL',
    'network' => 'MTN',
    'customer_phone' => '0551234567',
    'customer_name' => 'Nana Ama',
    'amount' => 200.00,
    'cashout_allowed_confirmed' => true,
    'telco_tx_id' => 'MTN-WD-99003',
], [], $vendorToken);
assertTrue($wdOk['status'] === 201, 'Withdrawal recorded after Allow Cash-Out + PIN confirmation');
$expectedCashAfter3 = $startCash + 500.0 + 305.0 - 200.0;
$expectedMtnAfter3 = $mtnBefore - 500.0 - 300.0 + 200.0;
assertTrue(abs($wdOk['body']['cash_balance'] - $expectedCashAfter3) < 0.001, 'Withdrawal decreased Physical Cash by -200.00');
assertTrue(abs($wdOk['body']['ecash_balance'] - $expectedMtnAfter3) < 0.001, 'Withdrawal increased MTN E-Cash by +200.00');

// 4. Ghana Telco SMS Parser Verification
echo "\n4. Ghana Telco SMS Parser (MTN MoMo, Telecel Cash, AirtelTigo Cash)\n";
$mtnParsed = SmsParserService::parse('Cash-In of GHS 750.00 to 0244887766 (KWESI APPIAH) was successful. Financial Transaction Id: 28499887766. Reference: Fees.');
assertTrue($mtnParsed['network'] === 'MTN' && $mtnParsed['tx_type'] === 'DEPOSIT' && $mtnParsed['amount'] === 750.0 && $mtnParsed['telco_tx_id'] === '28499887766', 'Parsed MTN Cash-In SMS accurately');

$telParsed = SmsParserService::parse('00009182736 Confirmed. You have sent GHS 420.50 to 0209112233 EFUA AIDOO on 29/10/26. New Telecel Cash balance is GHS 1,800.00. Ref: Support');
assertTrue($telParsed['network'] === 'TELECEL' && $telParsed['tx_type'] === 'TRANSFER' && $telParsed['amount'] === 420.5 && $telParsed['telco_tx_id'] === '00009182736', 'Parsed Telecel Transfer SMS accurately');

$atParsed = SmsParserService::parse('Trans ID: AT261029.1605.W8821. Cash Out of GHS 180.00 from 0274556677 Ebo Quansah successful. AT Money Bal: GHS 1,630.00.');
assertTrue($atParsed['network'] === 'AT' && $atParsed['tx_type'] === 'WITHDRAWAL' && $atParsed['amount'] === 180.0 && $atParsed['telco_tx_id'] === 'AT261029.1605.W8821', 'Parsed AirtelTigo Cash-Out SMS accurately');

// 5. Immutable Ledger Void Request & Boss Reversal
echo "\n5. Immutable Ledger Void Request & Boss Reversal\n";
$txToVoidId = (int) $depRes['body']['transaction_id'];
$vendorTryResolve = callApi('POST', "/api/transactions/{$txToVoidId}/void-resolve", ['decision' => 'APPROVE'], [], $vendorToken);
assertTrue($vendorTryResolve['status'] === 403, 'Vendor forbidden from approving their own void (HTTP 403)');

$voidReq = callApi('POST', "/api/transactions/{$txToVoidId}/void-request", [
    'reason' => 'Customer cancelled deposit; entered wrong amount.',
], [], $vendorToken);
assertTrue($voidReq['status'] === 200, 'Vendor submitted Void Request cleanly');

// Boss logs in
$bossLogin = callApi('POST', '/api/auth/login', [
    'identifier' => '0244000001',
    'password' => 'Password@123',
], [], '', $csrf);
assertTrue($bossLogin['status'] === 200, 'Main Boss Nana Kwaku Mensah signed in');
$bossToken = $bossLogin['body']['token'];
Security::logoutSession();

$voidApprove = callApi('POST', "/api/transactions/{$txToVoidId}/void-resolve", [
    'decision' => 'APPROVE',
    'note' => 'Verified on MTN Merchant App; reversing.',
], [], $bossToken);
assertTrue($voidApprove['status'] === 200 && $voidApprove['body']['status'] === 'VOIDED', 'Boss approved Void & reversed balances');
assertTrue(abs($voidApprove['body']['cash_balance'] - ($expectedCashAfter3 - 500.0)) < 0.001, 'Void reversal deducted +500 Cash back accurately');
assertTrue(abs($voidApprove['body']['ecash_balance'] - ($expectedMtnAfter3 + 500.0)) < 0.001, 'Void reversal restored +500 MTN E-Cash accurately');

// 6. Shift Closing & Shortage Detection
echo "\n6. Shift Closing & Shortage Variance Detection\n";
$vDashBeforeClose = callApi('GET', '/api/dashboard', [], [], $vendorToken);
$openShiftId = (int) $vDashBeforeClose['body']['open_shift']['id'];
$expCash = (float) $vDashBeforeClose['body']['branches'][0]['cash_balance'];

$closeRes = callApi('POST', "/api/shifts/{$openShiftId}/close", [
    'actual_cash' => $expCash - 50.00, // Simulate GH₵ 50.00 cash shortage!
    'notes' => 'Short by 50 GHS at closing count',
], [], $vendorToken);
assertTrue($closeRes['status'] === 200 && $closeRes['body']['status'] === 'FLAGGED', 'Shift flagged automatically on GH₵ 50.00 shortage');
assertTrue(abs($closeRes['body']['cash_variance'] - (-50.0)) < 0.001, 'Computed exact -50.00 cash variance');

// 7. Multi-Owner Tenant Isolation
echo "\n7. Multi-Owner SaaS Tenant Isolation\n";
$owner2Reg = callApi('POST', '/api/auth/register', [
    'company_name' => 'Accra FastFloat Ventures',
    'full_name' => ' Hajia Mariama ',
    'phone' => '0509876543',
    'password' => 'Secret@123',
    'first_branch_name' => 'Osu Oxford Street',
    'opening_cash' => 2500,
    'mtn_ecash' => 4000,
    'telecel_ecash' => 1500,
    'at_ecash' => 500,
], [], '', $csrf);
assertTrue($owner2Reg['status'] === 201, 'Second independent Enterprise Owner registered cleanly');
$owner2Token = $owner2Reg['body']['token'];
Security::logoutSession();

$owner2Dash = callApi('GET', '/api/dashboard', [], [], $owner2Token);
assertTrue(count($owner2Dash['body']['branches']) === 1 && $owner2Dash['body']['branches'][0]['name'] === 'Osu Oxford Street', 'Second Owner sees ONLY their own branch (strict multi-tenant isolation)');
assertTrue(count($owner2Dash['body']['recent_transactions']) === 0, 'Second Owner cannot see Move Money Enterprise transactions');

$crossVoidAttempt = callApi('POST', "/api/transactions/{$wdOk['body']['transaction_id']}/void-resolve", ['decision' => 'APPROVE'], [], $owner2Token);
assertTrue($crossVoidAttempt['status'] === 422, 'Second Owner blocked from touching Move Money Enterprise transactions');

echo "\n===================================================\n";
echo "Summary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
