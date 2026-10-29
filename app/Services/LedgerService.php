<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use InvalidArgumentException;
use RuntimeException;

final class LedgerService
{
    public const NETWORKS = ['MTN', 'TELECEL', 'AT'];
    public const TX_TYPES = ['DEPOSIT', 'TRANSFER', 'WITHDRAWAL'];

    public static function recordTransaction(array $user, array $input, string $ipAddress = '127.0.0.1'): array
    {
        $pdo = Database::connection();
        $companyId = (int) $user['company_id'];

        $branchId = isset($input['branch_id']) && (int) $input['branch_id'] > 0
            ? (int) $input['branch_id']
            : (int) ($user['branch_id'] ?? 0);

        if ($user['role'] === 'VENDOR') {
            if (empty($user['branch_id'])) {
                throw new RuntimeException('Your vendor account is not assigned to any branch yet. Ask your Boss to assign you.');
            }
            $branchId = (int) $user['branch_id'];
        }

        if ($branchId <= 0) {
            throw new InvalidArgumentException('Please select a branch for this transaction.');
        }

        $txType = strtoupper(trim((string) ($input['tx_type'] ?? '')));
        if (!in_array($txType, self::TX_TYPES, true)) {
            throw new InvalidArgumentException('Invalid transaction type. Choose Deposit, Transfer, or Withdrawal.');
        }

        $network = strtoupper(trim((string) ($input['network'] ?? '')));
        if (!in_array($network, self::NETWORKS, true)) {
            throw new InvalidArgumentException('Invalid mobile network. Choose MTN, Telecel, or AirtelTigo (AT).');
        }

        $receiverNetwork = strtoupper(trim((string) ($input['receiver_network'] ?? $network)));
        if ($txType !== 'TRANSFER' || $receiverNetwork === '') {
            $receiverNetwork = $network;
        }
        if (!in_array($receiverNetwork, self::NETWORKS, true)) {
            throw new InvalidArgumentException('Invalid receiver mobile network.');
        }

        $customerPhone = Security::normalizeGhanaPhone((string) ($input['customer_phone'] ?? ''));
        if (!Security::isValidGhanaPhone($customerPhone)) {
            throw new InvalidArgumentException('Enter a valid 10-digit Ghana mobile number (e.g. 0244123456).');
        }

        $amount = round((float) ($input['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Transaction amount must be greater than GH₵ 0.00.');
        }
        if ($amount > 500000) {
            throw new InvalidArgumentException('Amount exceeds maximum single transaction limit.');
        }

        $feeCharged = $txType === 'TRANSFER' ? max(0.0, round((float) ($input['fee_charged'] ?? 0), 2)) : 0.0;

        $cashoutConfirmed = !empty($input['cashout_allowed_confirmed']) ? 1 : 0;
        if ($txType === 'WITHDRAWAL' && $cashoutConfirmed !== 1) {
            throw new InvalidArgumentException('For Withdrawals, confirm that the customer enabled "Allow Cash Out" and completed their MoMo PIN.');
        }

        $telcoTxId = trim((string) ($input['telco_tx_id'] ?? ''));
        if ($telcoTxId === '' || strlen($telcoTxId) < 4) {
            throw new InvalidArgumentException('Enter the Telco SMS Transaction ID (at least 4 characters) for audit verification.');
        }
        $telcoTxId = mb_substr($telcoTxId, 0, 75);

        $customerName = trim((string) ($input['customer_name'] ?? '')) ?: null;
        $senderName = $txType === 'TRANSFER' ? (trim((string) ($input['sender_name'] ?? '')) ?: null) : null;
        $senderPhoneRaw = $txType === 'TRANSFER' ? trim((string) ($input['sender_phone'] ?? '')) : '';
        $senderPhone = $senderPhoneRaw !== '' ? Security::normalizeGhanaPhone($senderPhoneRaw) : null;
        $reference = trim((string) ($input['reference'] ?? '')) ?: null;
        $rawSms = trim((string) ($input['raw_sms'] ?? '')) ?: null;
        $entryMode = ($rawSms !== null && ($input['entry_mode'] ?? '') === 'SMS_PARSED') ? 'SMS_PARSED' : 'MANUAL';

        $lock = Database::forUpdate();
        $now = Database::now();

        $pdo->beginTransaction();
        try {
            // Check duplicate Telco Transaction ID within this enterprise & network
            $dupStmt = $pdo->prepare(
                'SELECT id FROM transactions WHERE company_id = ? AND network = ? AND telco_tx_id = ? LIMIT 1'
            );
            $dupStmt->execute([$companyId, $network, $telcoTxId]);
            if ($dupStmt->fetchColumn() !== false) {
                throw new InvalidArgumentException(
                    "Duplicate Transaction ID ({$telcoTxId}) on {$network}. This SMS transaction has already been recorded."
                );
            }

            // Lock Branch row
            $branchStmt = $pdo->prepare(
                "SELECT id, name, cash_balance, is_active FROM branches WHERE id = ? AND company_id = ? LIMIT 1{$lock}"
            );
            $branchStmt->execute([$branchId, $companyId]);
            $branch = $branchStmt->fetch();
            if (!$branch || (int) $branch['is_active'] !== 1) {
                throw new RuntimeException('Selected branch is not active or does not belong to your enterprise.');
            }

            // Lock Branch Network Wallet row
            $walletStmt = $pdo->prepare(
                "SELECT id, merchant_number, account_name, ecash_balance, is_active
                 FROM branch_wallets
                 WHERE branch_id = ? AND company_id = ? AND network = ?
                 LIMIT 1{$lock}"
            );
            $walletStmt->execute([$branchId, $companyId, $network]);
            $wallet = $walletStmt->fetch();
            if (!$wallet || (int) $wallet['is_active'] !== 1) {
                throw new RuntimeException("No active {$network} Merchant SIM wallet is configured for {$branch['name']}.");
            }

            $currentCash = round((float) $branch['cash_balance'], 2);
            $currentEcash = round((float) $wallet['ecash_balance'], 2);

            if ($txType === 'WITHDRAWAL') {
                // Money moves from customer's phone -> Merchant E-Cash (+amount); Merchant gives Physical Cash (-amount)
                if ($currentCash < $amount) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Insufficient Physical Cash in %s drawer (Available: GH₵ %s, Needed: GH₵ %s).',
                            $branch['name'],
                            number_format($currentCash, 2),
                            number_format($amount, 2)
                        )
                    );
                }
                $cashImpact = -$amount;
                $ecashImpact = +$amount;
            } else {
                // DEPOSIT or TRANSFER: Merchant sends E-Cash (-amount) and receives Physical Cash (+amount + optional fee)
                if ($currentEcash < $amount) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Insufficient %s E-Cash float on %s (Available: GH₵ %s, Needed: GH₵ %s).',
                            $network,
                            $wallet['merchant_number'],
                            number_format($currentEcash, 2),
                            number_format($amount, 2)
                        )
                    );
                }
                $cashImpact = round($amount + $feeCharged, 2);
                $ecashImpact = -$amount;
            }

            $newCash = round($currentCash + $cashImpact, 2);
            $newEcash = round($currentEcash + $ecashImpact, 2);

            // Attach open shift for this branch if any
            $shiftStmt = $pdo->prepare(
                "SELECT id FROM shifts WHERE branch_id = ? AND company_id = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1"
            );
            $shiftStmt->execute([$branchId, $companyId]);
            $shiftFound = $shiftStmt->fetchColumn();
            $shiftId = $shiftFound !== false ? (int) $shiftFound : null;

            // Update balances
            $pdo->prepare('UPDATE branches SET cash_balance = ?, updated_at = ? WHERE id = ?')
                ->execute([$newCash, $now, $branchId]);

            $pdo->prepare('UPDATE branch_wallets SET ecash_balance = ?, updated_at = ? WHERE id = ?')
                ->execute([$newEcash, $now, (int) $wallet['id']]);

            // Insert immutable transaction record
            $ins = $pdo->prepare(
                'INSERT INTO transactions (
                    company_id, branch_id, wallet_id, vendor_id, shift_id,
                    tx_type, network, receiver_network, customer_phone, customer_name,
                    sender_name, sender_phone, amount, fee_charged,
                    cash_impact, ecash_impact, cash_balance_after, ecash_balance_after,
                    cashout_allowed_confirmed, telco_tx_id, reference, raw_sms,
                    entry_mode, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $companyId,
                $branchId,
                (int) $wallet['id'],
                (int) $user['id'],
                $shiftId,
                $txType,
                $network,
                $receiverNetwork,
                $customerPhone,
                $customerName,
                $senderName,
                $senderPhone,
                $amount,
                $feeCharged,
                $cashImpact,
                $ecashImpact,
                $newCash,
                $newEcash,
                $cashoutConfirmed,
                $telcoTxId,
                $reference,
                $rawSms,
                $entryMode,
                'COMPLETED',
                $now,
            ]);
            $txId = (int) $pdo->lastInsertId();

            AuditService::log($companyId, (int) $user['id'], 'TX_RECORDED', 'transaction', $txId, [
                'tx_type' => $txType,
                'network' => $network,
                'amount' => $amount,
                'fee_charged' => $feeCharged,
                'telco_tx_id' => $telcoTxId,
                'cash_after' => $newCash,
                'ecash_after' => $newEcash,
            ], $ipAddress);

            $pdo->commit();

            return [
                'transaction_id' => $txId,
                'cash_balance' => $newCash,
                'ecash_balance' => $newEcash,
                'network' => $network,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function requestVoid(array $user, int $txId, string $reason, string $ipAddress = '127.0.0.1'): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('Please explain clearly why this transaction needs a void/correction (min 5 characters).');
        }

        $pdo = Database::connection();
        $companyId = (int) $user['company_id'];
        $now = Database::now();

        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$txId, $companyId]);
        $tx = $stmt->fetch();

        if (!$tx) {
            throw new InvalidArgumentException('Transaction not found.');
        }

        if ($user['role'] === 'VENDOR' && (int) $tx['branch_id'] !== (int) ($user['branch_id'] ?? 0)) {
            throw new RuntimeException('You can only request corrections for transactions at your assigned branch.');
        }

        if ($tx['status'] !== 'COMPLETED') {
            throw new InvalidArgumentException('Only completed transactions can be flagged for void/correction.');
        }

        $upd = $pdo->prepare(
            "UPDATE transactions
             SET status = 'VOID_REQUESTED', void_reason = ?, void_requested_by = ?, void_requested_at = ?
             WHERE id = ? AND company_id = ?"
        );
        $upd->execute([$reason, (int) $user['id'], $now, $txId, $companyId]);

        AuditService::log($companyId, (int) $user['id'], 'TX_VOID_REQUESTED', 'transaction', $txId, [
            'reason' => $reason,
            'telco_tx_id' => $tx['telco_tx_id'],
        ], $ipAddress);
    }

    public static function resolveVoid(
        array $bossUser,
        int $txId,
        string $decision,
        string $note = '',
        string $ipAddress = '127.0.0.1'
    ): array {
        $decision = strtoupper(trim($decision));
        if (!in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new InvalidArgumentException('Decision must be APPROVE or REJECT.');
        }

        $pdo = Database::connection();
        $companyId = (int) $bossUser['company_id'];
        $lock = Database::forUpdate();
        $now = Database::now();
        $note = trim($note) ?: ($decision === 'APPROVE' ? 'Void approved and balances reversed.' : 'Void request rejected.');

        $pdo->beginTransaction();
        try {
            $txStmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND company_id = ? LIMIT 1{$lock}");
            $txStmt->execute([$txId, $companyId]);
            $tx = $txStmt->fetch();

            if (!$tx) {
                throw new InvalidArgumentException('Transaction not found.');
            }
            if (!in_array($tx['status'], ['VOID_REQUESTED', 'COMPLETED'], true)) {
                throw new InvalidArgumentException('This transaction has already been voided.');
            }

            if ($decision === 'REJECT') {
                $pdo->prepare(
                    "UPDATE transactions
                     SET status = 'COMPLETED', void_resolved_by = ?, void_resolved_at = ?, void_resolution_note = ?
                     WHERE id = ?"
                )->execute([(int) $bossUser['id'], $now, $note, $txId]);

                AuditService::log($companyId, (int) $bossUser['id'], 'TX_VOID_REJECTED', 'transaction', $txId, [
                    'note' => $note,
                ], $ipAddress);

                $pdo->commit();
                return ['status' => 'COMPLETED'];
            }

            // APPROVE: Reverse exact Cash & SIM E-Cash impacts atomically
            $branchId = (int) $tx['branch_id'];
            $walletId = (int) $tx['wallet_id'];

            $branchStmt = $pdo->prepare("SELECT cash_balance FROM branches WHERE id = ? AND company_id = ? LIMIT 1{$lock}");
            $branchStmt->execute([$branchId, $companyId]);
            $branchCash = (float) $branchStmt->fetchColumn();

            $walletStmt = $pdo->prepare("SELECT ecash_balance FROM branch_wallets WHERE id = ? AND company_id = ? LIMIT 1{$lock}");
            $walletStmt->execute([$walletId, $companyId]);
            $walletEcash = (float) $walletStmt->fetchColumn();

            $reversedCash = round($branchCash - (float) $tx['cash_impact'], 2);
            $reversedEcash = round($walletEcash - (float) $tx['ecash_impact'], 2);

            if ($reversedCash < 0 || $reversedEcash < 0) {
                throw new InvalidArgumentException(
                    'Cannot reverse automatically because current branch Cash or SIM E-Cash would drop below GH₵ 0.00. Top up float first, then approve.'
                );
            }

            $pdo->prepare('UPDATE branches SET cash_balance = ?, updated_at = ? WHERE id = ?')
                ->execute([$reversedCash, $now, $branchId]);

            $pdo->prepare('UPDATE branch_wallets SET ecash_balance = ?, updated_at = ? WHERE id = ?')
                ->execute([$reversedEcash, $now, $walletId]);

            // Archive original telco_tx_id suffix so vendor can re-record genuine SMS ID if needed
            $archivedTxId = mb_substr($tx['telco_tx_id'], 0, 58) . '#VOID-' . $txId;

            $pdo->prepare(
                "UPDATE transactions
                 SET status = 'VOIDED',
                     telco_tx_id = ?,
                     void_resolved_by = ?,
                     void_resolved_at = ?,
                     void_resolution_note = ?
                 WHERE id = ?"
            )->execute([$archivedTxId, (int) $bossUser['id'], $now, $note, $txId]);

            AuditService::log($companyId, (int) $bossUser['id'], 'TX_VOID_APPROVED', 'transaction', $txId, [
                'original_telco_tx_id' => $tx['telco_tx_id'],
                'reversed_cash_impact' => -(float) $tx['cash_impact'],
                'reversed_ecash_impact' => -(float) $tx['ecash_impact'],
                'note' => $note,
            ], $ipAddress);

            $pdo->commit();

            return [
                'status' => 'VOIDED',
                'cash_balance' => $reversedCash,
                'ecash_balance' => $reversedEcash,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function adjustFloat(
        array $bossUser,
        int $branchId,
        string $bucket,
        string $direction,
        float $amount,
        string $reason,
        ?string $network = null,
        string $ipAddress = '127.0.0.1'
    ): array {
        $bucket = strtoupper(trim($bucket));
        $direction = strtoupper(trim($direction));
        $amount = round($amount, 2);
        $reason = trim($reason);

        if (!in_array($bucket, ['CASH', 'ECASH'], true)) {
            throw new InvalidArgumentException('Bucket must be CASH or ECASH.');
        }
        if (!in_array($direction, ['CREDIT', 'DEBIT'], true)) {
            throw new InvalidArgumentException('Direction must be CREDIT (Add) or DEBIT (Deduct).');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Adjustment amount must be greater than GH₵ 0.00.');
        }
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('Please provide a brief reason for this float adjustment.');
        }

        $pdo = Database::connection();
        $companyId = (int) $bossUser['company_id'];
        $lock = Database::forUpdate();
        $now = Database::now();

        $pdo->beginTransaction();
        try {
            $branchStmt = $pdo->prepare("SELECT id, name, cash_balance FROM branches WHERE id = ? AND company_id = ? LIMIT 1{$lock}");
            $branchStmt->execute([$branchId, $companyId]);
            $branch = $branchStmt->fetch();
            if (!$branch) {
                throw new InvalidArgumentException('Branch not found.');
            }

            $walletId = null;
            if ($bucket === 'CASH') {
                $current = round((float) $branch['cash_balance'], 2);
                $delta = $direction === 'CREDIT' ? $amount : -$amount;
                $after = round($current + $delta, 2);
                if ($after < 0) {
                    throw new InvalidArgumentException('Cannot deduct more physical cash than available in drawer.');
                }
                $pdo->prepare('UPDATE branches SET cash_balance = ?, updated_at = ? WHERE id = ?')
                    ->execute([$after, $now, $branchId]);
            } else {
                $net = strtoupper(trim((string) $network));
                if (!in_array($net, self::NETWORKS, true)) {
                    throw new InvalidArgumentException('Select MTN, TELECEL, or AT wallet for E-Cash float adjustment.');
                }
                $wStmt = $pdo->prepare(
                    "SELECT id, ecash_balance FROM branch_wallets WHERE branch_id = ? AND company_id = ? AND network = ? LIMIT 1{$lock}"
                );
                $wStmt->execute([$branchId, $companyId, $net]);
                $wallet = $wStmt->fetch();
                if (!$wallet) {
                    throw new InvalidArgumentException("No {$net} wallet found for this branch.");
                }
                $walletId = (int) $wallet['id'];
                $current = round((float) $wallet['ecash_balance'], 2);
                $delta = $direction === 'CREDIT' ? $amount : -$amount;
                $after = round($current + $delta, 2);
                if ($after < 0) {
                    throw new InvalidArgumentException("Cannot deduct more {$net} E-Cash than available on wallet.");
                }
                $pdo->prepare('UPDATE branch_wallets SET ecash_balance = ?, updated_at = ? WHERE id = ?')
                    ->execute([$after, $now, $walletId]);
            }

            $pdo->prepare(
                'INSERT INTO float_adjustments (company_id, branch_id, wallet_id, user_id, bucket, direction, amount, balance_after, reason, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$companyId, $branchId, $walletId, (int) $bossUser['id'], $bucket, $direction, $amount, $after, $reason, $now]);

            AuditService::log($companyId, (int) $bossUser['id'], 'FLOAT_ADJUSTED', 'branch', $branchId, [
                'bucket' => $bucket,
                'network' => $network,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $after,
                'reason' => $reason,
            ], $ipAddress);

            $pdo->commit();

            return [
                'bucket' => $bucket,
                'network' => $network,
                'balance_after' => $after,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
