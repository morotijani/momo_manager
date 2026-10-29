<?php

declare(strict_types=1);

namespace Database;

use App\Core\Database;
use PDO;

final class DemoSeeder
{
    public static function seedIfEmpty(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $now = Database::now();
        $todayPrefix = date('Y-m-d');
        $passwordHash = password_hash('Password@123', PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            // 1. Create Move Money Enterprise
            $stmtCompany = $pdo->prepare(
                'INSERT INTO companies (name, business_reg_no, owner_phone, currency, low_cash_threshold, low_ecash_threshold, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtCompany->execute([
                'Move Money Enterprise',
                'BN-2026-88412',
                '0244000001',
                'GHS',
                500.00,
                500.00,
                $now,
                $now,
            ]);
            $companyId = (int) $pdo->lastInsertId();

            // 2. Create 3 Branches with initial opening balances
            $branchesData = [
                [
                    'name' => 'Madina Zongo Junction',
                    'code' => 'BR-01',
                    'location' => 'Madina Zongo Junction, Accra',
                    'cash' => 4500.00,
                    'wallets' => [
                        'MTN' => ['num' => '0249900101', 'name' => 'MOVE MONEY - MADINA MTN', 'bal' => 9000.00],
                        'TELECEL' => ['num' => '0209900101', 'name' => 'MOVE MONEY - MADINA TCEL', 'bal' => 2500.00],
                        'AT' => ['num' => '0279900101', 'name' => 'MOVE MONEY - MADINA AT', 'bal' => 1450.00],
                    ],
                    'vendor' => ['name' => 'Ama Boateng', 'phone' => '0244000002', 'email' => 'ama@movemoney.gh'],
                ],
                [
                    'name' => 'Circle Kwame Nkrumah',
                    'code' => 'BR-02',
                    'location' => 'Tip Toe Lane, Circle, Accra',
                    'cash' => 6000.00,
                    'wallets' => [
                        'MTN' => ['num' => '0249900202', 'name' => 'MOVE MONEY - CIRCLE MTN', 'bal' => 12000.00],
                        'TELECEL' => ['num' => '0209900202', 'name' => 'MOVE MONEY - CIRCLE TCEL', 'bal' => 3100.00],
                        'AT' => ['num' => '0279900202', 'name' => 'MOVE MONEY - CIRCLE AT', 'bal' => 580.00],
                    ],
                    'vendor' => ['name' => 'Kwame Asante', 'phone' => '0544000003', 'email' => 'kwame@movemoney.gh'],
                ],
                [
                    'name' => 'Kasoa New Market',
                    'code' => 'BR-03',
                    'location' => 'Kasoa Overpass Market',
                    'cash' => 3800.00,
                    'wallets' => [
                        'MTN' => ['num' => '0249900303', 'name' => 'MOVE MONEY - KASOA MTN', 'bal' => 5500.00],
                        'TELECEL' => ['num' => '0209900303', 'name' => 'MOVE MONEY - KASOA TCEL', 'bal' => 1950.00],
                        'AT' => ['num' => '0279900303', 'name' => 'MOVE MONEY - KASOA AT', 'bal' => 1100.00],
                    ],
                    'vendor' => ['name' => 'Efua Owusu', 'phone' => '0204000004', 'email' => 'efua@movemoney.gh'],
                ],
            ];

            // Create Owner (Main Boss)
            $stmtUser = $pdo->prepare(
                'INSERT INTO users (company_id, branch_id, full_name, phone, email, password_hash, role, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmtUser->execute([
                $companyId,
                null,
                'Nana Kwaku Mensah',
                '0244000001',
                'boss@movemoney.gh',
                $passwordHash,
                'OWNER',
                $now,
                $now,
            ]);
            $ownerId = (int) $pdo->lastInsertId();

            $stmtBranch = $pdo->prepare(
                'INSERT INTO branches (company_id, name, code, location, cash_balance, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmtWallet = $pdo->prepare(
                'INSERT INTO branch_wallets (company_id, branch_id, network, merchant_number, account_name, ecash_balance, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmtShift = $pdo->prepare(
                'INSERT INTO shifts (company_id, branch_id, vendor_id, opening_cash, opening_wallets_json, status, opened_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $branchesMeta = [];
            foreach ($branchesData as $idx => $b) {
                $stmtBranch->execute([$companyId, $b['name'], $b['code'], $b['location'], $b['cash'], $now, $now]);
                $branchId = (int) $pdo->lastInsertId();

                $walletIds = [];
                $walletSnap = [];
                foreach ($b['wallets'] as $net => $w) {
                    $stmtWallet->execute([$companyId, $branchId, $net, $w['num'], $w['name'], $w['bal'], $now, $now]);
                    $walletIds[$net] = (int) $pdo->lastInsertId();
                    $walletSnap[$net] = $w['bal'];
                }

                $stmtUser->execute([
                    $companyId,
                    $branchId,
                    $b['vendor']['name'],
                    $b['vendor']['phone'],
                    $b['vendor']['email'],
                    $passwordHash,
                    'VENDOR',
                    $now,
                    $now,
                ]);
                $vendorId = (int) $pdo->lastInsertId();

                $openedAt = $todayPrefix . ' 07:3' . $idx . ':00';
                $stmtShift->execute([
                    $companyId,
                    $branchId,
                    $vendorId,
                    $b['cash'],
                    json_encode($walletSnap),
                    'OPEN',
                    $openedAt,
                ]);
                $shiftId = (int) $pdo->lastInsertId();

                $branchesMeta[] = [
                    'branch_id' => $branchId,
                    'vendor_id' => $vendorId,
                    'shift_id' => $shiftId,
                    'cash' => $b['cash'],
                    'wallets' => $walletIds,
                    'wallet_bals' => $walletSnap,
                ];
            }

            // 3. Seed realistic sample transactions updating live balances accurately
            $sampleTxs = [
                // Branch 0 (Madina - Ama Boateng)
                [
                    'b' => 0, 'type' => 'DEPOSIT', 'net' => 'MTN', 'rnet' => 'MTN',
                    'phone' => '0248112233', 'cname' => 'Kofi Boateng', 'sname' => null, 'sphone' => null,
                    'amt' => 600.00, 'fee' => 0.00, 'cashout' => 0,
                    'txid' => '28491038471', 'ref' => 'Personal wallet deposit', 'mode' => 'SMS_PARSED',
                    'sms' => 'Cash-In of GHS 600.00 to 0248112233 (KOFI BOATENG) was successful. Financial Transaction Id: 28491038471. Reference: Personal wallet deposit.',
                    'status' => 'COMPLETED', 'time' => $todayPrefix . ' 08:15:10',
                ],
                [
                    'b' => 0, 'type' => 'WITHDRAWAL', 'net' => 'MTN', 'rnet' => 'MTN',
                    'phone' => '0542998877', 'cname' => 'Abena Osei', 'sname' => null, 'sphone' => null,
                    'amt' => 400.00, 'fee' => 0.00, 'cashout' => 1,
                    'txid' => '28491055102', 'ref' => 'Cash-out withdrawal', 'mode' => 'MANUAL',
                    'sms' => null,
                    'status' => 'COMPLETED', 'time' => $todayPrefix . ' 09:05:44',
                ],
                [
                    'b' => 0, 'type' => 'TRANSFER', 'net' => 'TELECEL', 'rnet' => 'TELECEL',
                    'phone' => '0207334455', 'cname' => 'Esi Mansa', 'sname' => 'Yaw Mensah', 'sphone' => '0243110099',
                    'amt' => 250.00, 'fee' => 5.00, 'cashout' => 0,
                    'txid' => '00001829341', 'ref' => 'School upkeep', 'mode' => 'SMS_PARSED',
                    'sms' => '00001829341 Confirmed. You have sent GHS250.00 to 0207334455 ESI MANSA. Ref: School upkeep',
                    'status' => 'COMPLETED', 'time' => $todayPrefix . ' 10:22:18',
                ],
                // Branch 1 (Circle - Kwame Asante)
                [
                    'b' => 1, 'type' => 'DEPOSIT', 'net' => 'MTN', 'rnet' => 'MTN',
                    'phone' => '0556778899', 'cname' => 'Ibrahim Mohammed', 'sname' => null, 'sphone' => null,
                    'amt' => 1200.00, 'fee' => 0.00, 'cashout' => 0,
                    'txid' => '28492011890', 'ref' => 'Shop restock', 'mode' => 'SMS_PARSED',
                    'sms' => 'Cash-In of GHS 1,200.00 to 0556778899 (IBRAHIM MOHAMMED) was successful. Financial Transaction Id: 28492011890. Reference: Shop restock.',
                    'status' => 'COMPLETED', 'time' => $todayPrefix . ' 08:42:05',
                ],
                [
                    'b' => 1, 'type' => 'WITHDRAWAL', 'net' => 'MTN', 'rnet' => 'MTN',
                    'phone' => '0241239876', 'cname' => 'Grace Tetteh', 'sname' => null, 'sphone' => null,
                    'amt' => 850.00, 'fee' => 0.00, 'cashout' => 1,
                    'txid' => '28492044512', 'ref' => 'Market cashout', 'mode' => 'MANUAL',
                    'sms' => null,
                    'status' => 'COMPLETED', 'time' => $todayPrefix . ' 09:48:30',
                ],
                [
                    'b' => 1, 'type' => 'TRANSFER', 'net' => 'AT', 'rnet' => 'AT',
                    'phone' => '0275667788', 'cname' => 'Nii Armah', 'sname' => 'Adwoa Serwaa', 'sphone' => '0268001122',
                    'amt' => 250.00, 'fee' => 5.00, 'cashout' => 0,
                    'txid' => 'AT261029.1015.C09182', 'ref' => 'Family support', 'mode' => 'MANUAL',
                    'sms' => null,
                    'status' => 'VOID_REQUESTED', 'void_reason' => 'Entered 250 instead of 200 by mistake; customer brought 200 GHS.',
                    'time' => $todayPrefix . ' 10:15:00',
                ],
                // Branch 2 (Kasoa - Efua Owusu)
                [
                    'b' => 2, 'type' => 'WITHDRAWAL', 'net' => 'MTN', 'rnet' => 'MTN',
                    'phone' => '0593445566', 'cname' => 'Kojo Antwi', 'sname' => null, 'sphone' => null,
                    'amt' => 500.00, 'fee' => 0.00, 'cashout' => 1,
                    'txid' => '28493019283', 'ref' => 'Allow cashout withdrawal', 'mode' => 'SMS_PARSED',
                    'sms' => 'Cash-Out of GHS 500.00 from 0593445566 (KOJO ANTWI) successful. Financial Transaction Id: 28493019283.',
                    'status' => 'COMPLETED', 'time' => $todayPrefix . ' 09:12:51',
                ],
                [
                    'b' => 2, 'type' => 'DEPOSIT', 'net' => 'TELECEL', 'rnet' => 'TELECEL',
                    'phone' => '0502113344', 'cname' => 'Akua Agyeman', 'sname' => null, 'sphone' => null,
                    'amt' => 300.00, 'fee' => 0.00, 'cashout' => 0,
                    'txid' => '00001830412', 'ref' => 'Self deposit', 'mode' => 'MANUAL',
                    'sms' => null,
                    'status' => 'COMPLETED', 'time' => $todayPrefix . ' 11:04:19',
                ],
            ];

            $stmtTx = $pdo->prepare(
                'INSERT INTO transactions (
                    company_id, branch_id, wallet_id, vendor_id, shift_id,
                    tx_type, network, receiver_network, customer_phone, customer_name,
                    sender_name, sender_phone, amount, fee_charged,
                    cash_impact, ecash_impact, cash_balance_after, ecash_balance_after,
                    cashout_allowed_confirmed, telco_tx_id, reference, raw_sms,
                    entry_mode, status, void_reason, void_requested_by, void_requested_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmtUpdBranch = $pdo->prepare('UPDATE branches SET cash_balance = ?, updated_at = ? WHERE id = ?');
            $stmtUpdWallet = $pdo->prepare('UPDATE branch_wallets SET ecash_balance = ?, updated_at = ? WHERE id = ?');

            foreach ($sampleTxs as $tx) {
                $bm = &$branchesMeta[$tx['b']];
                $net = $tx['net'];
                $walletId = $bm['wallets'][$net];

                if ($tx['type'] === 'WITHDRAWAL') {
                    $cashImpact = -$tx['amt'];
                    $ecashImpact = +$tx['amt'];
                } else {
                    $cashImpact = $tx['amt'] + $tx['fee'];
                    $ecashImpact = -$tx['amt'];
                }

                $bm['cash'] = round($bm['cash'] + $cashImpact, 2);
                $bm['wallet_bals'][$net] = round($bm['wallet_bals'][$net] + $ecashImpact, 2);

                $voidReason = $tx['void_reason'] ?? null;
                $voidReqBy = $tx['status'] === 'VOID_REQUESTED' ? $bm['vendor_id'] : null;
                $voidReqAt = $tx['status'] === 'VOID_REQUESTED' ? $tx['time'] : null;

                $stmtTx->execute([
                    $companyId,
                    $bm['branch_id'],
                    $walletId,
                    $bm['vendor_id'],
                    $bm['shift_id'],
                    $tx['type'],
                    $net,
                    $tx['rnet'],
                    $tx['phone'],
                    $tx['cname'],
                    $tx['sname'],
                    $tx['sphone'],
                    $tx['amt'],
                    $tx['fee'],
                    $cashImpact,
                    $ecashImpact,
                    $bm['cash'],
                    $bm['wallet_bals'][$net],
                    $tx['cashout'],
                    $tx['txid'],
                    $tx['ref'],
                    $tx['sms'],
                    $tx['mode'],
                    $tx['status'],
                    $voidReason,
                    $voidReqBy,
                    $voidReqAt,
                    $tx['time'],
                ]);

                $stmtUpdBranch->execute([$bm['cash'], $now, $bm['branch_id']]);
                $stmtUpdWallet->execute([$bm['wallet_bals'][$net], $now, $walletId]);
            }
            unset($bm);

            $stmtAudit = $pdo->prepare(
                'INSERT INTO audit_logs (company_id, user_id, action, entity_type, entity_id, details_json, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtAudit->execute([
                $companyId,
                $ownerId,
                'ENTERPRISE_CREATED',
                'company',
                $companyId,
                json_encode(['name' => 'Move Money Enterprise', 'seeded' => true]),
                '127.0.0.1',
                $now,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
