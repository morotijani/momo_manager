<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Security;

final class SmsParserService
{
    public static function detectNetworkFromPhone(string $phone): ?string
    {
        $normalized = Security::normalizeGhanaPhone($phone);
        if (strlen($normalized) < 3) {
            return null;
        }
        $prefix = substr($normalized, 0, 3);
        return match ($prefix) {
            '024', '054', '055', '059', '025', '053' => 'MTN',
            '020', '050' => 'TELECEL',
            '027', '057', '026', '056' => 'AT',
            default => null,
        };
    }

    public static function parse(string $rawSms): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $rawSms) ?? '');
        if ($text === '') {
            return ['matched' => false];
        }

        $lower = strtolower($text);

        // 1. Detect Network from telco keywords first
        $network = null;
        if (
            str_contains($lower, 'financial transaction id') ||
            str_contains($lower, 'mtn') ||
            str_contains($lower, 'momo')
        ) {
            $network = 'MTN';
        } elseif (
            str_contains($lower, 'telecel') ||
            str_contains($lower, 'vodafone') ||
            preg_match('/^\d{10,12}\s+confirmed/i', $text)
        ) {
            $network = 'TELECEL';
        } elseif (
            str_contains($lower, 'airteltigo') ||
            str_contains($lower, 'at money') ||
            str_contains($lower, 'at cash') ||
            preg_match('/\bAT\d{6}\.\d{4}\.[A-Z0-9]+\b/i', $text)
        ) {
            $network = 'AT';
        }

        // 2. Detect Transaction Type
        $txType = null;
        if (
            preg_match('/\b(cash[\s-]*out|withdrawn|withdrawal)\b/i', $text)
        ) {
            $txType = 'WITHDRAWAL';
        } elseif (
            preg_match('/\b(cash[\s-]*in|deposited|deposit)\b/i', $text)
        ) {
            $txType = 'DEPOSIT';
        } elseif (
            preg_match('/\b(sent|transferred|transfer|payment made)\b/i', $text)
        ) {
            $txType = 'TRANSFER';
        }

        // 3. Extract Principal Amount (first GHS / GH₵ amount before balance/commission)
        $amount = null;
        if (preg_match('/(?:cash[\s-]*in|cash[\s-]*out|sent|transferred|deposited|payment made|of|for)\s+(?:of\s+|for\s+)?(?:GHS|GH₵|GHC)\s*([0-9,]+(?:\.\d{1,2})?)/i', $text, $m)) {
            $amount = round((float) str_replace(',', '', $m[1]), 2);
        } elseif (preg_match('/(?:GHS|GH₵|GHC)\s*([0-9,]+(?:\.\d{1,2})?)/i', $text, $m)) {
            $amount = round((float) str_replace(',', '', $m[1]), 2);
        }

        // 4. Extract Customer/Receiver Phone Number
        $customerPhone = null;
        if (preg_match('/\b(\+?233[235]\d{8}|0[235]\d{8})\b/', $text, $m)) {
            $customerPhone = Security::normalizeGhanaPhone($m[1]);
        }

        if ($network === null && $customerPhone !== null) {
            $network = self::detectNetworkFromPhone($customerPhone);
        }

        // 5. Extract Customer/Receiver Name
        $customerName = null;
        // Pattern: 0248112233 (KOFI BOATENG)
        if (preg_match('/(?:\+?233[235]\d{8}|0[235]\d{8})\s*\(\s*([A-Za-z][A-Za-z\s.\'-]{2,50})\s*\)/', $text, $m)) {
            $customerName = ucwords(strtolower(trim($m[1])));
        } elseif (preg_match('/to\s+([A-Z][A-Z\s.\'-]{2,45})\s*\(\s*(?:\+?233|0)[235]\d{8}\s*\)/', $text, $m)) {
            $customerName = ucwords(strtolower(trim($m[1])));
        } elseif (preg_match('/(?:to|from)\s+(?:\+?233[235]\d{8}|0[235]\d{8})\s*[-:]?\s*([A-Z][A-Za-z\s.\'-]{2,40}?)(?=\s+(?:on|was|successful|at|new|current|bal|trans|financial|ref|\.)|$)/i', $text, $m)) {
            $candidate = trim($m[1]);
            if (!preg_match('/^(was|successful|confirmed|on|at)$/i', $candidate)) {
                $customerName = ucwords(strtolower($candidate));
            }
        }

        // 6. Extract Telco Transaction ID
        $telcoTxId = null;
        if (preg_match('/(?:financial\s+transaction\s+id|transaction\s+id|trans(?:action)?\s*id|txn\s*id)\s*[:#-]?\s*([A-Za-z0-9._-]{6,40})/i', $text, $m)) {
            $telcoTxId = rtrim($m[1], '.');
        } elseif (preg_match('/^([0-9]{9,15})\s+Confirmed\b/i', $text, $m)) {
            $telcoTxId = $m[1];
        } elseif (preg_match('/\b(AT\d{6}\.\d{4}\.[A-Za-z0-9]+)\b/i', $text, $m)) {
            $telcoTxId = strtoupper($m[1]);
        }

        // 7. Extract Reference
        $reference = null;
        if (preg_match('/(?:reference|ref)\s*[:#-]?\s*([^.;\n]{1,80})/i', $text, $m)) {
            $refClean = trim($m[1]);
            $refClean = preg_replace('/\s*(?:current balance|new balance|commission|bal).*$/i', '', $refClean) ?? $refClean;
            $reference = rtrim(trim($refClean), '.');
        }

        $matchedCount = count(array_filter([$network, $txType, $amount, $customerPhone, $telcoTxId], static fn ($v) => $v !== null && $v !== ''));

        return [
            'matched' => $matchedCount >= 2,
            'confidence' => $matchedCount >= 4 ? 'HIGH' : ($matchedCount >= 2 ? 'MEDIUM' : 'LOW'),
            'network' => $network,
            'tx_type' => $txType,
            'amount' => $amount,
            'customer_phone' => $customerPhone,
            'customer_name' => $customerName,
            'telco_tx_id' => $telcoTxId,
            'reference' => $reference,
        ];
    }
}
