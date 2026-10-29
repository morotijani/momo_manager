/**
 * Ghana Mobile Money Prefix Detector & Telco SMS Parser
 * Supports MTN MoMo, Telecel Cash, and AirtelTigo Cash (AT Money)
 */
window.MomoSmsParser = (function () {
  function normalizePhone(raw) {
    const digits = String(raw || '').replace(/\D+/g, '');
    if (digits.startsWith('233') && digits.length === 12) {
      return '0' + digits.slice(3);
    }
    if (digits.length === 9 && !digits.startsWith('0')) {
      return '0' + digits;
    }
    return digits;
  }

  function detectNetworkFromPhone(raw) {
    const p = normalizePhone(raw);
    if (p.length < 3) return null;
    const prefix = p.slice(0, 3);
    if (['024', '054', '055', '059', '025', '053'].includes(prefix)) return 'MTN';
    if (['020', '050'].includes(prefix)) return 'TELECEL';
    if (['027', '057', '026', '056'].includes(prefix)) return 'AT';
    return null;
  }

  function titleCase(str) {
    return String(str || '')
      .trim()
      .toLowerCase()
      .replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function parse(rawSms) {
    const text = String(rawSms || '').replace(/\s+/g, ' ').trim();
    if (!text) return { matched: false };

    const lower = text.toLowerCase();

    let network = null;
    if (lower.includes('financial transaction id') || lower.includes('mtn') || lower.includes('momo')) {
      network = 'MTN';
    } else if (lower.includes('telecel') || lower.includes('vodafone') || /^\d{10,12}\s+confirmed/i.test(text)) {
      network = 'TELECEL';
    } else if (
      lower.includes('airteltigo') ||
      lower.includes('at money') ||
      lower.includes('at cash') ||
      /\bAT\d{6}\.\d{4}\.[A-Z0-9]+\b/i.test(text)
    ) {
      network = 'AT';
    }

    let tx_type = null;
    if (/\b(cash[\s-]*out|withdrawn|withdrawal)\b/i.test(text)) {
      tx_type = 'WITHDRAWAL';
    } else if (/\b(cash[\s-]*in|deposited|deposit)\b/i.test(text)) {
      tx_type = 'DEPOSIT';
    } else if (/\b(sent|transferred|transfer|payment made)\b/i.test(text)) {
      tx_type = 'TRANSFER';
    }

    let amount = null;
    const amtMatch1 = text.match(/(?:cash[\s-]*in|cash[\s-]*out|sent|transferred|deposited|payment made|of|for)\s+(?:of\s+|for\s+)?(?:GHS|GH₵|GHC)\s*([0-9,]+(?:\.\d{1,2})?)/i);
    const amtMatch2 = text.match(/(?:GHS|GH₵|GHC)\s*([0-9,]+(?:\.\d{1,2})?)/i);
    if (amtMatch1) {
      amount = parseFloat(amtMatch1[1].replace(/,/g, ''));
    } else if (amtMatch2) {
      amount = parseFloat(amtMatch2[1].replace(/,/g, ''));
    }

    let customer_phone = null;
    const phoneMatch = text.match(/\b(\+?233[235]\d{8}|0[235]\d{8})\b/);
    if (phoneMatch) {
      customer_phone = normalizePhone(phoneMatch[1]);
    }

    if (!network && customer_phone) {
      network = detectNetworkFromPhone(customer_phone);
    }

    let customer_name = '';
    const nameMatch1 = text.match(/(?:\+?233[235]\d{8}|0[235]\d{8})\s*\(\s*([A-Za-z][A-Za-z\s.'-]{2,50})\s*\)/);
    const nameMatch2 = text.match(/to\s+([A-Z][A-Z\s.'-]{2,45})\s*\(\s*(?:\+?233|0)[235]\d{8}\s*\)/);
    const nameMatch3 = text.match(/(?:to|from)\s+(?:\+?233[235]\d{8}|0[235]\d{8})\s*[-:]?\s*([A-Z][A-Za-z\s.'-]{2,40}?)(?=\s+(?:on|was|successful|at|new|current|bal|trans|financial|ref|\.)|$)/i);
    if (nameMatch1) {
      customer_name = titleCase(nameMatch1[1]);
    } else if (nameMatch2) {
      customer_name = titleCase(nameMatch2[1]);
    } else if (nameMatch3 && !/^(was|successful|confirmed|on|at)$/i.test(nameMatch3[1].trim())) {
      customer_name = titleCase(nameMatch3[1]);
    }

    let telco_tx_id = '';
    const idMatch1 = text.match(/(?:financial\s+transaction\s+id|transaction\s+id|trans(?:action)?\s*id|txn\s*id)\s*[:#-]?\s*([A-Za-z0-9._-]{6,40})/i);
    const idMatch2 = text.match(/^([0-9]{9,15})\s+Confirmed\b/i);
    const idMatch3 = text.match(/\b(AT\d{6}\.\d{4}\.[A-Za-z0-9]+)\b/i);
    if (idMatch1) {
      telco_tx_id = idMatch1[1].replace(/\.$/, '');
    } else if (idMatch2) {
      telco_tx_id = idMatch2[1];
    } else if (idMatch3) {
      telco_tx_id = idMatch3[1].toUpperCase();
    }

    let reference = '';
    const refMatch = text.match(/(?:reference|ref)\s*[:#-]?\s*([^.;\n]{1,80})/i);
    if (refMatch) {
      reference = refMatch[1]
        .replace(/\s*(?:current balance|new balance|commission|bal).*$/i, '')
        .replace(/\.$/, '')
        .trim();
    }

    const hits = [network, tx_type, amount, customer_phone, telco_tx_id].filter(Boolean).length;

    return {
      matched: hits >= 2,
      network,
      tx_type,
      amount,
      customer_phone,
      customer_name,
      telco_tx_id,
      reference,
    };
  }

  function generateSampleSms(kind) {
    const rand = Math.floor(100000 + Math.random() * 899999);
    if (kind === 'MTN_DEPOSIT') {
      return `Cash-In of GHS 350.00 to 0245889900 (YAW FRIMPONG) was successful. Financial Transaction Id: 28499${rand}. Reference: Personal Deposit. Current Balance: GHS 8,050.00. Commission: GHS 1.75.`;
    }
    if (kind === 'TELECEL_TRANSFER') {
      return `00009${rand} Confirmed. You have sent GHS 200.00 to 0208445566 AKOSUA MENSAH on 29/10/26. New Telecel Cash balance is GHS 2,150.00. Ref: Upkeep money`;
    }
    return `Trans ID: AT261029.1540.W${String(rand).slice(0, 4)}. Cash Out of GHS 150.00 from 0276332211 Kweku Baako successful. AT Money Bal: GHS 1,600.00. Ref: Cashout`;
  }

  return {
    normalizePhone,
    detectNetworkFromPhone,
    parse,
    generateSampleSms,
  };
})();
