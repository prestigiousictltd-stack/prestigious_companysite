<?php
/**
 * Sub-account engine.
 *
 * A sub-account is a NIN-bearing entity under a parent partner. It has no
 * login. Its commission flows into the parent's ledger, tagged with its
 * own sub_account_id so balances can be tracked and withdrawn per bucket.
 *
 * With PDO::ATTR_EMULATE_PREPARES = false, every named placeholder in a
 * statement must appear exactly once.
 */

declare(strict_types=1);

// =====================================================================
// Resolution
// =====================================================================

/**
 * Look up a sub-account by NIN.
 *
 * @param bool $activeOnly  When true, only ACTIVE rows are returned
 *                          (used by the CSV import path).
 *                          When false, PENDING/ACTIVE/SUSPENDED are
 *                          also returned (used by uniqueness checks).
 */
function sub_account_resolve_by_nin(string $nin, bool $activeOnly = true): ?array
{
    $clean = preg_replace('/\D/', '', $nin);
    if (!preg_match('/^\d{11}$/', (string) $clean)) {
        return null;
    }

    if ($activeOnly) {
        $sql = "SELECT * FROM partner_sub_accounts WHERE nin_number = :n AND status = 'ACTIVE' LIMIT 1";
    } else {
        $sql = "SELECT * FROM partner_sub_accounts WHERE nin_number = :n AND status IN ('PENDING','ACTIVE','SUSPENDED') LIMIT 1";
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([':n' => $clean]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Look up a sub-account by id.
 */
function sub_account_find(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM partner_sub_accounts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Resolve the commission rate applicable to a sub-account on a given date.
 * Mirrors the partner and aggregator rate resolvers, including the
 * earliest-rate fallback for backdated imports.
 */
function sub_account_resolve_rate(int $subAccountId, string $effectiveDate): ?array
{
    $stmt = db()->prepare("
        SELECT * FROM sub_account_commission_rates
        WHERE sub_account_id = :sid
          AND status = 'ACTIVE'
          AND effective_from <= :date_from
          AND (effective_to IS NULL OR effective_to >= :date_to)
        ORDER BY effective_from DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':sid'       => $subAccountId,
        ':date_from' => $effectiveDate,
        ':date_to'   => $effectiveDate,
    ]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // Fallback: earliest rate whose window starts after the requested date.
    $stmt = db()->prepare("
        SELECT * FROM sub_account_commission_rates
        WHERE sub_account_id = :sid
          AND status = 'ACTIVE'
          AND effective_from > :date_from
        ORDER BY effective_from ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([
        ':sid'       => $subAccountId,
        ':date_from' => $effectiveDate,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Current rate row for today's date. */
function sub_account_current_rate(int $subAccountId): ?array
{
    return sub_account_resolve_rate($subAccountId, today_date());
}

/**
 * Compute sub-account commission from NIN count and revenue per NIN.
 * Mirrors commission_compute() from includes/commission.php but uses the
 * sub-account rate. Returns the same shape.
 */
function sub_account_compute(int $ninGenerated, float $revenuePerNin, ?array $rateRow): array
{
    if ($rateRow === null) {
        throw new RuntimeException('No applicable sub-account commission rate');
    }
    $pct = (float) $rateRow['commission_percentage'];
    $gross = round($ninGenerated * $revenuePerNin, 2);
    $commission = round($gross * $pct / 100, 2);
    return [
        'gross_revenue'     => $gross,
        'commission_amount' => $commission,
        'pct'               => $pct,
        'rate_id'           => (int) $rateRow['id'],
    ];
}

/**
 * Whether a new effective-dated rate range would overlap an existing ACTIVE one.
 */
function sub_account_would_overlap(int $subAccountId, string $from, ?string $to, ?int $exceptId = null): bool
{
    $sql = "SELECT COUNT(*) FROM sub_account_commission_rates
            WHERE sub_account_id = :sid
              AND status = 'ACTIVE'
              AND (:range_from <= COALESCE(effective_to, '9999-12-31'))
              AND (COALESCE(:range_to, '9999-12-31') >= effective_from)";
    $params = [
        ':sid'        => $subAccountId,
        ':range_from' => $from,
        ':range_to'   => $to,
    ];
    if ($exceptId !== null) {
        $sql .= ' AND id <> :except_id';
        $params[':except_id'] = $exceptId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return ((int) $stmt->fetchColumn()) > 0;
}

// =====================================================================
// Uniqueness — cross-table NIN check
// =====================================================================

/**
 * Check whether a NIN is already in use anywhere on the platform.
 *
 * A NIN is "in use" if:
 *   - a partner row exists with status PENDING, ACTIVE or SUSPENDED, or
 *   - a sub-account row exists with status PENDING, ACTIVE or SUSPENDED.
 *
 * INACTIVE and REJECTED rows do not block reuse.
 *
 * @param string   $nin                The 11-digit NIN to test
 * @param int|null $excludePartnerId   Skip this partner row (used when editing)
 * @param int|null $excludeSubAccountId Skip this sub-account row
 *
 * @return array{taken: bool, kind: ?string, reason: string}
 */
function sub_account_nin_is_taken(
    string $nin,
    ?int $excludePartnerId = null,
    ?int $excludeSubAccountId = null
): array {
    $clean = preg_replace('/\D/', '', $nin);
    if (!preg_match('/^\d{11}$/', (string) $clean)) {
        return ['taken' => false, 'kind' => null, 'reason' => ''];
    }

    // Partners — uses the generated active_nin column
    $sql = "SELECT id, full_name, status FROM partners WHERE active_nin = :n";
    $params = [':n' => $clean];
    if ($excludePartnerId !== null) {
        $sql .= " AND id <> :epid";
        $params[':epid'] = $excludePartnerId;
    }
    $sql .= " LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $p = $stmt->fetch();
    if ($p) {
        return [
            'taken'  => true,
            'kind'   => 'partner',
            'reason' => 'This NIN is already registered as a partner ('
                . $p['full_name'] . ' — ' . strtolower($p['status']) . ').',
        ];
    }

    // Sub-accounts — uses the generated active_nin column
    $sql = "SELECT id, full_name, status, partner_id FROM partner_sub_accounts WHERE active_nin = :n";
    $params = [':n' => $clean];
    if ($excludeSubAccountId !== null) {
        $sql .= " AND id <> :esid";
        $params[':esid'] = $excludeSubAccountId;
    }
    $sql .= " LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $s = $stmt->fetch();
    if ($s) {
        return [
            'taken'  => true,
            'kind'   => 'sub_account',
            'reason' => 'This NIN is already registered as a sub-account ('
                . $s['full_name'] . ' — ' . strtolower($s['status']) . ').',
        ];
    }

    return ['taken' => false, 'kind' => null, 'reason' => ''];
}

// =====================================================================
// Ledger and balance — bucket-aware
// =====================================================================

/**
 * Current available balance for a partner bucket.
 * Pass $subAccountId = null to get the partner's own bucket.
 */
function partner_bucket_balance(int $partnerId, ?int $subAccountId): float
{
    if ($subAccountId === null) {
        $stmt = db()->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM partner_financial_transactions
            WHERE partner_id = :pid AND sub_account_id IS NULL
        ");
        $stmt->execute([':pid' => $partnerId]);
    } else {
        $stmt = db()->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM partner_financial_transactions
            WHERE partner_id = :pid AND sub_account_id = :sid
        ");
        $stmt->execute([':pid' => $partnerId, ':sid' => $subAccountId]);
    }
    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * Current available balance for a specific sub-account.
 * Convenience wrapper around partner_bucket_balance().
 */
function sub_account_balance(int $subAccountId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM partner_financial_transactions
        WHERE sub_account_id = :sid
    ");
    $stmt->execute([':sid' => $subAccountId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/** Total commission earned by a sub-account (positive number). */
function sub_account_total_commission(int $subAccountId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM partner_financial_transactions
        WHERE sub_account_id = :sid AND type = 'COMMISSION_EARNED'
    ");
    $stmt->execute([':sid' => $subAccountId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/** Total paid out for a sub-account (positive number). */
function sub_account_total_withdrawn(int $subAccountId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(-amount), 0)
        FROM partner_financial_transactions
        WHERE sub_account_id = :sid AND type = 'WITHDRAWAL_PAID'
    ");
    $stmt->execute([':sid' => $subAccountId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/** Total reserved for a sub-account (pending/approved/processing withdrawals). */
function sub_account_total_reserved(int $subAccountId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM withdrawals
        WHERE sub_account_id = :sid AND status IN ('PENDING','APPROVED','PROCESSING')
    ");
    $stmt->execute([':sid' => $subAccountId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * Aggregate stats across the partner's own NIN plus every sub-account.
 * Used by the partner dashboard's cumulative summary.
 *
 * @return array{
 *   total_balance: float,
 *   total_commission: float,
 *   total_withdrawn: float,
 *   total_reserved: float,
 *   own_balance: float,
 *   own_commission: float,
 *   sub_count: int,
 * }
 */
function partner_aggregate_bucket_stats(int $partnerId): array
{
    // Own bucket
    $stmt = db()->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN sub_account_id IS NULL THEN amount ELSE 0 END), 0) AS own_balance,
          COALESCE(SUM(CASE WHEN sub_account_id IS NULL AND type = 'COMMISSION_EARNED' THEN amount ELSE 0 END), 0) AS own_commission,
          COALESCE(SUM(CASE WHEN type = 'COMMISSION_EARNED' THEN amount ELSE 0 END), 0) AS total_commission,
          COALESCE(SUM(CASE WHEN type = 'WITHDRAWAL_PAID' THEN -amount ELSE 0 END), 0) AS total_withdrawn,
          COALESCE(SUM(amount), 0) AS total_balance
        FROM partner_financial_transactions
        WHERE partner_id = :pid
    ");
    $stmt->execute([':pid' => $partnerId]);
    $row = $stmt->fetch() ?: [
        'own_balance' => 0, 'own_commission' => 0,
        'total_commission' => 0, 'total_withdrawn' => 0, 'total_balance' => 0,
    ];

    // Reserved total (any bucket)
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM withdrawals
        WHERE partner_id = :pid AND status IN ('PENDING','APPROVED','PROCESSING')
    ");
    $stmt->execute([':pid' => $partnerId]);
    $totalReserved = round((float) $stmt->fetchColumn(), 2);

    // Sub-account count
    $stmt = db()->prepare("
        SELECT
          SUM(CASE WHEN status = 'ACTIVE'   THEN 1 ELSE 0 END) AS active_count,
          SUM(CASE WHEN status = 'PENDING'  THEN 1 ELSE 0 END) AS pending_count
        FROM partner_sub_accounts
        WHERE partner_id = :pid
    ");
    $stmt->execute([':pid' => $partnerId]);
    $counts = $stmt->fetch() ?: ['active_count' => 0, 'pending_count' => 0];

    return [
        'total_balance'    => round((float) $row['total_balance'], 2),
        'total_commission' => round((float) $row['total_commission'], 2),
        'total_withdrawn'  => round((float) $row['total_withdrawn'], 2),
        'total_reserved'   => $totalReserved,
        'own_balance'      => round((float) $row['own_balance'], 2),
        'own_commission'   => round((float) $row['own_commission'], 2),
        'sub_count'        => (int) $counts['active_count'],
        'pending_count'    => (int) $counts['pending_count'],
    ];
}

// =====================================================================
// Settlement helper — used by CSV import integration and backfill
// =====================================================================

/**
 * Attach an existing partner_enrolment_records row to a sub-account and
 * credit the parent partner's ledger under that sub-account's bucket.
 *
 * Assumes the enrolment record currently has sub_account_id = NULL (either
 * an orphan that has been re-attributed, or a partner-owned record that is
 * being moved). Idempotent on the ledger side — it will not double-credit.
 */
/**
 * Attach an existing partner_enrolment_records row to a sub-account.
 *
 * Two cases are handled:
 *
 *   1. Orphan record (partner_id IS NULL, sub_account_id IS NULL)
 *      → attach to the sub-account. Write a fresh COMMISSION_EARNED
 *        ledger entry in the sub-account bucket.
 *
 *   2. Partner-own record (partner_id = sub-account's parent, sub_account_id IS NULL)
 *      → reattribute. Reverse the partner-own ledger entry, then write a
 *        new COMMISSION_EARNED in the sub-account bucket. This keeps the
 *        buckets from double-counting the same enrolment.
 *
 * Cross-partner attaches are rejected. Records already attached to the
 * target sub-account are treated as idempotent no-ops.
 *
 * @return array{ok: bool, reason: string, credited: bool, amount: float, was_reattribution: bool}
 */
function sub_account_attach_enrolment(int $enrolmentId, int $subAccountId, int $saId): array
{
    require_once PICT_ROOT . '/includes/financial.php';

    $stmt = db()->prepare("SELECT * FROM partner_enrolment_records WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $enrolmentId]);
    $enrolment = $stmt->fetch();
    if (!$enrolment) {
        return ['ok' => false, 'reason' => 'Enrolment record not found.', 'credited' => false, 'amount' => 0.0, 'was_reattribution' => false];
    }

    $sub = sub_account_find($subAccountId);
    if (!$sub || $sub['status'] !== 'ACTIVE') {
        return ['ok' => false, 'reason' => 'Sub-account not found or not active.', 'credited' => false, 'amount' => 0.0, 'was_reattribution' => false];
    }

    // Cross-partner guard
    if ($enrolment['partner_id'] !== null
        && (int) $sub['partner_id'] !== (int) $enrolment['partner_id']) {
        return ['ok' => false, 'reason' => 'Enrolment belongs to a different partner.', 'credited' => false, 'amount' => 0.0, 'was_reattribution' => false];
    }

    // NIN sanity check
    $enrolNin = preg_replace('/\D/', '', (string) $enrolment['partner_nin_raw']);
    if ($enrolNin !== (string) $sub['nin_number']) {
        return ['ok' => false, 'reason' => 'Enrolment NIN does not match the sub-account NIN.', 'credited' => false, 'amount' => 0.0, 'was_reattribution' => false];
    }

    // Already attached to this sub-account?
    if ((int) ($enrolment['sub_account_id'] ?? 0) === $subAccountId) {
        return ['ok' => true, 'reason' => 'Already attached.', 'credited' => false, 'amount' => (float) $enrolment['commission_amount'], 'was_reattribution' => false];
    }

    // Determine whether this is a reattribution from the partner-own bucket
    $isReattribution = ($enrolment['partner_id'] !== null && $enrolment['sub_account_id'] === null);

    // Look up the prior partner-own ledger entry (only relevant for reattribution)
    $priorLedger = null;
    if ($isReattribution) {
        $st = db()->prepare("
            SELECT id, amount FROM partner_financial_transactions
            WHERE related_enrolment_id = :eid
              AND sub_account_id IS NULL
              AND type = 'COMMISSION_EARNED'
            LIMIT 1
        ");
        $st->execute([':eid' => $enrolmentId]);
        $priorLedger = $st->fetch() ?: null;
    }

    // Idempotency: is there already a sub-account credit for this enrolment?
    $chk = db()->prepare("
        SELECT id FROM partner_financial_transactions
        WHERE related_enrolment_id = :eid
          AND sub_account_id = :sid
          AND type = 'COMMISSION_EARNED'
        LIMIT 1
    ");
    $chk->execute([':eid' => $enrolmentId, ':sid' => $subAccountId]);
    $alreadyAttached = (bool) $chk->fetchColumn();

    $effectiveDate = (string) ($enrolment['effective_date_used'] ?? '');
    if ($effectiveDate === '') {
        $effectiveDate = sprintf('%04d-%02d-01', (int) $enrolment['period_year'], (int) $enrolment['period_month']);
    }

    $rate = sub_account_resolve_rate($subAccountId, $effectiveDate);
    if ($rate === null) {
        return ['ok' => false, 'reason' => 'No applicable rate for the sub-account on ' . $effectiveDate, 'credited' => false, 'amount' => 0.0, 'was_reattribution' => false];
    }

    $nin           = (int) $enrolment['total_nin_generated'];
    $revenuePerNin = (float) $enrolment['revenue_per_nin'];
    $snap          = sub_account_compute($nin, $revenuePerNin, $rate);

    // Update the enrolment record
    db()->prepare("
        UPDATE partner_enrolment_records SET
            sub_account_id          = :sid,
            partner_id              = :pid,
            commission_percentage   = :pct,
            commission_rate_id      = NULL,
            gross_revenue           = :gr,
            commission_amount       = :ca
        WHERE id = :id
    ")->execute([
        ':sid' => $subAccountId,
        ':pid' => (int) $sub['partner_id'],
        ':pct' => $snap['pct'],
        ':gr'  => $snap['gross_revenue'],
        ':ca'  => $snap['commission_amount'],
        ':id'  => $enrolmentId,
    ]);

    $credited = false;

    if (!$alreadyAttached) {
        // Reverse any prior partner-own credit so the buckets do not double-count
        if ($priorLedger !== null && (float) $priorLedger['amount'] != 0.0) {
            ledger_record(
                (int) $sub['partner_id'],
                'REVERSAL',
                -(float) $priorLedger['amount'],
                'REATTACH-' . $enrolmentId,
                'Reversal: enrolment moved to sub-account ' . $sub['full_name'],
                ['year' => (int) $enrolment['period_year'], 'month' => (int) $enrolment['period_month']],
                ['enrolment_id' => $enrolmentId, 'import_batch_id' => (int) $enrolment['import_batch_id']],
                'SUPER_ADMIN',
                $saId,
                null  // Reverses from the partner-own bucket
            );
        }

        // Credit the sub-account bucket
        if ($snap['commission_amount'] > 0) {
            ledger_record(
                (int) $sub['partner_id'],
                'COMMISSION_EARNED',
                $snap['commission_amount'],
                'SUBATTACH-' . $enrolmentId,
                'Sub-account commission for ' . format_period((int) $enrolment['period_year'], (int) $enrolment['period_month'])
                    . ' (' . $sub['full_name'] . ')',
                ['year' => (int) $enrolment['period_year'], 'month' => (int) $enrolment['period_month']],
                ['enrolment_id' => $enrolmentId, 'import_batch_id' => (int) $enrolment['import_batch_id']],
                'SUPER_ADMIN',
                $saId,
                $subAccountId
            );
        }
        $credited = true;
    }

    return [
        'ok' => true,
        'reason' => '',
        'credited' => $credited,
        'amount' => $snap['commission_amount'],
        'was_reattribution' => $isReattribution,
    ];
}
// =====================================================================
// Approval helper
// =====================================================================

/**
 * Approve a PENDING sub-account. Creates the initial rate and, optionally,
 * backfills historical enrolment records whose NIN matches this sub-account.
 *
 * @return array{ok: bool, reason: string, backfilled: int}
 */
function sub_account_approve(
    int $subAccountId,
    float $ratePct,
    string $effectiveFrom,
    int $saId,
    bool $runBackfill = true
): array {
    $sub = sub_account_find($subAccountId);
    if (!$sub) {
        return ['ok' => false, 'reason' => 'Sub-account not found.', 'backfilled' => 0];
    }
    if ($sub['status'] !== 'PENDING') {
        return ['ok' => false, 'reason' => 'Only pending sub-accounts can be approved.', 'backfilled' => 0];
    }
    if ($ratePct < 0 || $ratePct > 100) {
        return ['ok' => false, 'reason' => 'Commission percentage must be between 0 and 100.', 'backfilled' => 0];
    }

    $now = now_dt();

    // Update status
    db()->prepare("
        UPDATE partner_sub_accounts SET
            status                      = 'ACTIVE',
            approved_by_super_admin_id  = :by,
            approved_at                 = :at,
            status_changed_by_id        = :by2,
            status_changed_at           = :at2,
            updated_at                  = :at3
        WHERE id = :id
    ")->execute([
        ':by'  => $saId,
        ':at'  => $now,
        ':by2' => $saId,
        ':at2' => $now,
        ':at3' => $now,
        ':id'  => $subAccountId,
    ]);

    // Create initial rate
    db()->prepare("
        INSERT INTO sub_account_commission_rates
          (sub_account_id, commission_percentage, effective_from, effective_to,
           status, notes, created_by_super_admin_id, created_at)
        VALUES
          (:sid, :pct, :ef, NULL,
           'ACTIVE', 'Initial rate at approval', :by, :ca)
    ")->execute([
        ':sid' => $subAccountId,
        ':pct' => $ratePct,
        ':ef'  => $effectiveFrom,
        ':by'  => $saId,
        ':ca'  => $now,
    ]);

    // Optionally backfill historical enrolment records that match this NIN
    $backfilled = 0;
    if ($runBackfill) {
        $stmt = db()->prepare("
            SELECT id FROM partner_enrolment_records
            WHERE partner_nin_raw = :nin
              AND (sub_account_id IS NULL OR sub_account_id = :sid2)
              AND commission_amount >= 0
            ORDER BY period_year ASC, period_month ASC, id ASC
        ");
        $stmt->execute([':nin' => $sub['nin_number'], ':sid2' => $subAccountId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            $res = sub_account_attach_enrolment((int) $r['id'], $subAccountId, $saId);
            if (!empty($res['ok']) && !empty($res['credited'])) {
                $backfilled++;
            }
        }
    }

    return ['ok' => true, 'reason' => '', 'backfilled' => $backfilled];
}
// =====================================================================
// CSV import resolution — three-way NIN lookup
// =====================================================================

/**
 * Resolve a NIN as it appears in a CSV row.
 *
 * Order of resolution:
 *   1. The NIN belongs to a partner's own NIN   → entity = 'partner'
 *   2. The NIN belongs to an active sub-account → entity = 'sub_account'
 *   3. Otherwise                                → entity = 'orphan'
 *
 * A partner or sub-account that exists but is not ACTIVE is treated as an
 * orphan — the record is preserved and settles when the entity is activated.
 *
 * Returns:
 *   [
 *     'entity'            => 'partner' | 'sub_account' | 'orphan',
 *     'partner_id'        => int|null,
 *     'sub_account_id'    => int|null,
 *     'display_name'      => string,
 *     'partner_own_name'  => string,   // partner's legal name, even for sub-accounts
 *     'partner_code'      => string,
 *     'rate_row'          => array|null,
 *     'reason'            => string,   // for orphans/inactive
 *   ]
 */
function csv_resolve_nin(string $nin, string $effectiveDate): array
{
    $clean = preg_replace('/\D/', '', $nin);
    if (!preg_match('/^\d{11}$/', (string) $clean)) {
        return [
            'entity' => 'orphan', 'partner_id' => null, 'sub_account_id' => null,
            'display_name' => '(invalid NIN)', 'partner_own_name' => '', 'partner_code' => '',
            'rate_row' => null, 'reason' => 'NIN must be 11 digits',
        ];
    }

    // 1. Partner's own NIN
    $stmt = db()->prepare("
        SELECT id, full_name, partner_code, status
        FROM partners
        WHERE nin_number = :n
        LIMIT 1
    ");
    $stmt->execute([':n' => $clean]);
    $p = $stmt->fetch();
    if ($p) {
        if ($p['status'] === 'ACTIVE') {
            $rate = commission_resolve_rate((int) $p['id'], $effectiveDate);
            return [
                'entity'           => 'partner',
                'partner_id'       => (int) $p['id'],
                'sub_account_id'   => null,
                'display_name'     => (string) $p['full_name'],
                'partner_own_name' => (string) $p['full_name'],
                'partner_code'     => (string) $p['partner_code'],
                'rate_row'         => $rate,
                'reason'           => '',
            ];
        }
        // Exists but not ACTIVE — orphan
        return [
            'entity' => 'orphan', 'partner_id' => null, 'sub_account_id' => null,
            'display_name' => '(inactive: ' . $p['full_name'] . ')',
            'partner_own_name' => (string) $p['full_name'],
            'partner_code' => (string) $p['partner_code'],
            'rate_row' => null,
            'reason' => 'Partner is not ACTIVE',
        ];
    }

    // 2. Sub-account NIN
    $stmt = db()->prepare("
        SELECT s.id, s.partner_id, s.full_name, s.status AS sub_status,
               p.full_name AS partner_name, p.partner_code, p.status AS partner_status
        FROM partner_sub_accounts s
        JOIN partners p ON p.id = s.partner_id
        WHERE s.nin_number = :n
        LIMIT 1
    ");
    $stmt->execute([':n' => $clean]);
    $s = $stmt->fetch();
    if ($s) {
        if ($s['sub_status'] === 'ACTIVE' && $s['partner_status'] === 'ACTIVE') {
            $rate = sub_account_resolve_rate((int) $s['id'], $effectiveDate);
            return [
                'entity'           => 'sub_account',
                'partner_id'       => (int) $s['partner_id'],
                'sub_account_id'   => (int) $s['id'],
                'display_name'     => (string) $s['full_name'] . ' (' . $s['partner_name'] . ')',
                'partner_own_name' => (string) $s['partner_name'],
                'partner_code'     => (string) $s['partner_code'],
                'rate_row'         => $rate,
                'reason'           => '',
            ];
        }
        return [
            'entity' => 'orphan', 'partner_id' => null, 'sub_account_id' => null,
            'display_name' => '(inactive sub-account: ' . $s['full_name'] . ')',
            'partner_own_name' => (string) $s['partner_name'],
            'partner_code' => (string) $s['partner_code'],
            'rate_row' => null,
            'reason' => 'Sub-account is not ACTIVE',
        ];
    }

    // 3. Orphan
    return [
        'entity' => 'orphan', 'partner_id' => null, 'sub_account_id' => null,
        'display_name' => '(unmatched NIN)',
        'partner_own_name' => '', 'partner_code' => '',
        'rate_row' => null,
        'reason' => 'Unknown NIN',
    ];
}
// =====================================================================
// Reassignment
// =====================================================================

/**
 * Move a sub-account from one parent partner to another.
 *
 * All of the sub-account's data moves with it in one transaction:
 *   - partner_sub_accounts.partner_id
 *   - partner_financial_transactions.partner_id   (where sub_account_id = X)
 *   - partner_enrolment_records.partner_id        (where sub_account_id = X)
 *   - withdrawals.partner_id                      (where sub_account_id = X)
 *   - partner_aggregator_assignments.partner_id   (where sub_account_id = X)
 *
 * Refuses to run when:
 *   - the sub-account is PENDING or REJECTED
 *   - the destination partner does not exist or is not ACTIVE
 *   - the destination is the same as the current parent
 *   - the sub-account has pending / approved / processing withdrawals
 *   - the NIN is now taken by someone else under the destination
 *
 * @return array{ok: bool, reason: string, old_partner_id: int, new_partner_id: int, balance_moved: float}
 */
function sub_account_reassign(int $subAccountId, int $newPartnerId, int $saId): array
{
    require_once PICT_ROOT . '/includes/financial.php';

    $fail = function (string $reason) {
        return [
            'ok' => false, 'reason' => $reason,
            'old_partner_id' => 0, 'new_partner_id' => 0, 'balance_moved' => 0.0,
        ];
    };

    $sub = sub_account_find($subAccountId);
    if (!$sub) {
        return $fail('Sub-account not found.');
    }

    $oldPartnerId = (int) $sub['partner_id'];

    if ($oldPartnerId === $newPartnerId) {
        return $fail('The sub-account already belongs to that partner.');
    }

    if (!in_array($sub['status'], ['ACTIVE','INACTIVE'], true)) {
        return $fail('Only ACTIVE or INACTIVE sub-accounts can be reassigned.');
    }

    // Destination must exist and be ACTIVE
    $stmt = db()->prepare("SELECT id, full_name, partner_code, status FROM partners WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $newPartnerId]);
    $dest = $stmt->fetch();
    if (!$dest) {
        return $fail('Destination partner not found.');
    }
    if ($dest['status'] !== 'ACTIVE') {
        return $fail('Destination partner is not ACTIVE.');
    }

    // Block when there are unresolved withdrawals
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM withdrawals
        WHERE sub_account_id = :sid AND status IN ('PENDING','APPROVED','PROCESSING')
    ");
    $stmt->execute([':sid' => $subAccountId]);
    $pendingWithdrawals = (int) $stmt->fetchColumn();
    if ($pendingWithdrawals > 0) {
        return $fail(
            'This sub-account has ' . $pendingWithdrawals
            . ' unresolved withdrawal(s). Approve, pay or reject them before reassigning.'
        );
    }

    // Destination partner's own NIN must not collide with the sub-account's NIN
    // (belt-and-braces — the current uniqueness rules already prevent this,
    // but the check protects against any future change).
    $collision = sub_account_nin_is_taken((string) $sub['nin_number'], $newPartnerId, $subAccountId);
    if (!empty($collision['taken'])) {
        return $fail($collision['reason']);
    }

    // Compute the balance that will move
    $balanceMoved = sub_account_balance($subAccountId);

    $now = now_dt();

    try {
        db()->beginTransaction();

        // 1. Re-parent the sub-account itself
        db()->prepare("
            UPDATE partner_sub_accounts
            SET partner_id = :pid, updated_at = :ua
            WHERE id = :id
        ")->execute([':pid' => $newPartnerId, ':ua' => $now, ':id' => $subAccountId]);

        // 2. Move the ledger bucket
        db()->prepare("
            UPDATE partner_financial_transactions
            SET partner_id = :pid
            WHERE sub_account_id = :sid
        ")->execute([':pid' => $newPartnerId, ':sid' => $subAccountId]);

        // 3. Move enrolment records
        db()->prepare("
            UPDATE partner_enrolment_records
            SET partner_id = :pid
            WHERE sub_account_id = :sid
        ")->execute([':pid' => $newPartnerId, ':sid' => $subAccountId]);

        // 4. Move historical withdrawals
        db()->prepare("
            UPDATE withdrawals
            SET partner_id = :pid
            WHERE sub_account_id = :sid
        ")->execute([':pid' => $newPartnerId, ':sid' => $subAccountId]);

        // 5. Move aggregator links that were tied to this specific sub-account
        db()->prepare("
            UPDATE partner_aggregator_assignments
            SET partner_id = :pid
            WHERE sub_account_id = :sid
        ")->execute([':pid' => $newPartnerId, ':sid' => $subAccountId]);

        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[sub-account-reassign] ' . $ex->getMessage());
        return $fail('Reassignment failed: ' . $ex->getMessage());
    }

    return [
        'ok' => true,
        'reason' => '',
        'old_partner_id' => $oldPartnerId,
        'new_partner_id' => $newPartnerId,
        'balance_moved' => $balanceMoved,
    ];
}