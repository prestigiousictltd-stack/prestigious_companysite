<?php
/**
 * Aggregator engine.
 *
 * An aggregator earns a percentage of the commission earned by the partners
 * they introduced. The aggregator's cut is additional — it does not reduce
 * the partner's own commission.
 *
 * Base for the calculation is partner_enrolment_records.commission_amount
 * (the partner's net commission for that enrolment), NOT gross revenue.
 *
 * With PDO::ATTR_EMULATE_PREPARES = false, every named placeholder in a
 * statement must appear exactly once.
 */

declare(strict_types=1);

/**
 * Resolve the commission rate applicable to an aggregator on a given date.
 * Falls back to the earliest ACTIVE rate if none covers the date (matches
 * the partner-side behaviour so backdated imports do not fail on onboarding).
 */
function aggregator_resolve_rate(int $aggregatorId, string $effectiveDate): ?array
{
    $stmt = db()->prepare("
        SELECT * FROM aggregator_commission_rates
        WHERE aggregator_id = :aid
          AND status = 'ACTIVE'
          AND effective_from <= :date_from
          AND (effective_to IS NULL OR effective_to >= :date_to)
        ORDER BY effective_from DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':aid'       => $aggregatorId,
        ':date_from' => $effectiveDate,
        ':date_to'   => $effectiveDate,
    ]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // Fallback: earliest rate after the requested date
    $stmt = db()->prepare("
        SELECT * FROM aggregator_commission_rates
        WHERE aggregator_id = :aid
          AND status = 'ACTIVE'
          AND effective_from > :date_from
        ORDER BY effective_from ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([
        ':aid'       => $aggregatorId,
        ':date_from' => $effectiveDate,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Resolve the aggregator assigned to a partner on a given date.
 * Returns ['aggregator_id' => int, 'assignment_id' => int, 'aggregator_name' => string]
 * or null if the partner had no aggregator then.
 */
function aggregator_resolve_for_partner(int $partnerId, string $effectiveDate): ?array
{
    $stmt = db()->prepare("
        SELECT a.id AS assignment_id, a.aggregator_id, ag.full_name AS aggregator_name
        FROM partner_aggregator_assignments a
        JOIN aggregators ag ON ag.id = a.aggregator_id
        WHERE a.partner_id = :pid
          AND a.status = 'ACTIVE'
          AND a.effective_from <= :date_from
          AND (a.effective_to IS NULL OR a.effective_to >= :date_to)
        ORDER BY a.effective_from DESC, a.id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':pid'       => $partnerId,
        ':date_from' => $effectiveDate,
        ':date_to'   => $effectiveDate,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Compute the aggregator's cut from a partner's net commission.
 */
function aggregator_compute(float $partnerCommission, float $pct): float
{
    return round($partnerCommission * $pct / 100, 2);
}

/**
 * Append an aggregator ledger entry. Assumes the caller is inside a
 * transaction and has already locked the aggregator row.
 *
 * @param float $amountSigned  Positive for COMMISSION_EARNED, negative for PAYOUT.
 */
function aggregator_ledger_record(
    int $aggregatorId,
    string $type,
    float $amountSigned,
    ?string $reference = null,
    ?string $description = null,
    array $period = [],
    array $related = [],
    string $actorRole = 'SYSTEM',
    ?int $actorId = null
): int {
    if (!in_array($type, ['COMMISSION_EARNED','PAYOUT','ADJUSTMENT','REVERSAL'], true)) {
        throw new InvalidArgumentException('Invalid aggregator ledger type: ' . $type);
    }

    $prev = aggregator_available_balance($aggregatorId);
    $new  = round($prev + $amountSigned, 2);

    $txId = 'AGG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

    $stmt = db()->prepare("
        INSERT INTO aggregator_financial_transactions
          (transaction_id, aggregator_id, reference, amount, type,
           period_year, period_month, description,
           previous_balance, new_balance,
           related_settlement_id, related_import_batch_id,
           created_by_role, created_by_id, created_at)
        VALUES
          (:tid, :aid, :ref, :amt, :type,
           :py, :pm, :desc,
           :prev, :new,
           :rsid, :rbid,
           :cbr, :cbi, :ca)
    ");
    $stmt->execute([
        ':tid'  => $txId,
        ':aid'  => $aggregatorId,
        ':ref'  => $reference,
        ':amt'  => $amountSigned,
        ':type' => $type,
        ':py'   => $period['year']  ?? null,
        ':pm'   => $period['month'] ?? null,
        ':desc' => $description,
        ':prev' => $prev,
        ':new'  => $new,
        ':rsid' => $related['settlement_id'] ?? null,
        ':rbid' => $related['import_batch_id'] ?? null,
        ':cbr'  => $actorRole,
        ':cbi'  => $actorId,
        ':ca'   => now_dt(),
    ]);

    return (int) db()->lastInsertId();
}

/** Current available balance = SUM(amount) across the aggregator ledger. */
function aggregator_available_balance(int $aggregatorId): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM aggregator_financial_transactions WHERE aggregator_id = :aid");
    $stmt->execute([':aid' => $aggregatorId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/** Total commission earned (positive number). */
function aggregator_total_earned(int $aggregatorId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM aggregator_financial_transactions
        WHERE aggregator_id = :aid AND type = 'COMMISSION_EARNED'
    ");
    $stmt->execute([':aid' => $aggregatorId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/** Total paid out (positive number). */
function aggregator_total_paid(int $aggregatorId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(-amount), 0)
        FROM aggregator_financial_transactions
        WHERE aggregator_id = :aid AND type = 'PAYOUT'
    ");
    $stmt->execute([':aid' => $aggregatorId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/** Count of partners currently attached to the aggregator. */
function aggregator_active_partner_count(int $aggregatorId): int
{
    $stmt = db()->prepare("
        SELECT COUNT(DISTINCT partner_id)
        FROM partner_aggregator_assignments
        WHERE aggregator_id = :aid AND status = 'ACTIVE'
    ");
    $stmt->execute([':aid' => $aggregatorId]);
    return (int) $stmt->fetchColumn();
}

/** Get the currently active rate row for an aggregator (today). */
function aggregator_current_rate(int $aggregatorId): ?array
{
    return aggregator_resolve_rate($aggregatorId, today_date());
}

/**
 * Whether a new effective-dated rate range would overlap an existing ACTIVE one.
 */
function aggregator_would_overlap(int $aggregatorId, string $from, ?string $to, ?int $exceptId = null): bool
{
    $sql = "SELECT COUNT(*) FROM aggregator_commission_rates
            WHERE aggregator_id = :aid
              AND status = 'ACTIVE'
              AND (:range_from <= COALESCE(effective_to, '9999-12-31'))
              AND (COALESCE(:range_to, '9999-12-31') >= effective_from)";
    $params = [
        ':aid'        => $aggregatorId,
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

/**
 * Assign a partner to an aggregator, closing any prior active assignment
 * whose effective_from is before the new one.
 *
 * The two references to $effectiveFrom use distinct placeholder names
 * (:new_from_a, :new_from_b) because PDO::ATTR_EMULATE_PREPARES = false
 * does not allow a placeholder to appear twice in the same statement.
 */
function aggregator_assign_partner(
    int $partnerId,
    int $aggregatorId,
    string $effectiveFrom,
    int $saId,
    ?string $notes = null
): int {
    // Close any prior ACTIVE assignment that started before the new date.
    db()->prepare("
        UPDATE partner_aggregator_assignments
        SET status = 'SUPERSEDED',
            effective_to = DATE_SUB(:new_from_a, INTERVAL 1 DAY)
        WHERE partner_id = :pid_a
          AND status = 'ACTIVE'
          AND effective_from < :new_from_b
    ")->execute([
        ':new_from_a' => $effectiveFrom,
        ':pid_a'      => $partnerId,
        ':new_from_b' => $effectiveFrom,
    ]);

    // Insert the new assignment.
    $stmt = db()->prepare("
        INSERT INTO partner_aggregator_assignments
          (partner_id, aggregator_id, effective_from, effective_to, status,
           assigned_by_super_admin_id, assigned_at, notes)
        VALUES
          (:pid, :aid, :ef, NULL, 'ACTIVE',
           :by, :at, :notes)
    ");
    $stmt->execute([
        ':pid'   => $partnerId,
        ':aid'   => $aggregatorId,
        ':ef'    => $effectiveFrom,
        ':by'    => $saId,
        ':at'    => now_dt(),
        ':notes' => $notes,
    ]);
    return (int) db()->lastInsertId();
}
/**
 * Create an aggregator settlement for a partner enrolment record.
 * Idempotent — returns the existing settlement id if one is already present.
 *
 * Used by both the CSV import integration and the backfill tool.
 *
 * @param array $enrolment   A partner_enrolment_records row
 * @param array|null $assignment  Result of aggregator_resolve_for_partner()
 * @param int $saId          Super Admin performing the action
 * @return array             ['settlement_id' => int, 'created' => bool, 'amount' => float, 'pct' => float, 'aggregator_id' => int]
 */
function aggregator_create_settlement(array $enrolment, ?array $assignment, int $saId): array
{
    if ($assignment === null) {
        return ['settlement_id' => 0, 'created' => false, 'amount' => 0.0, 'pct' => 0.0, 'aggregator_id' => 0];
    }

    $aggregatorId = (int) $assignment['aggregator_id'];
    $enrolmentId  = (int) $enrolment['id'];
    $effectiveDate = (string) ($enrolment['effective_date_used'] ?? '');
    if ($effectiveDate === '') {
        $effectiveDate = sprintf('%04d-%02d-01', (int) $enrolment['period_year'], (int) $enrolment['period_month']);
    }

    // Idempotency: if a settlement exists, return it unchanged
    $chk = db()->prepare("
        SELECT id, aggregator_commission_amount, aggregator_percentage
        FROM aggregator_enrolment_records
        WHERE partner_enrolment_id = :eid AND aggregator_id = :aid
        LIMIT 1
    ");
    $chk->execute([':eid' => $enrolmentId, ':aid' => $aggregatorId]);
    $existing = $chk->fetch();
    if ($existing) {
        return [
            'settlement_id' => (int) $existing['id'],
            'created'       => false,
            'amount'        => (float) $existing['aggregator_commission_amount'],
            'pct'           => (float) $existing['aggregator_percentage'],
            'aggregator_id' => $aggregatorId,
        ];
    }

    // Resolve the aggregator's rate at the enrolment's period
    $rate = aggregator_resolve_rate($aggregatorId, $effectiveDate);
    if (!$rate) {
        return ['settlement_id' => 0, 'created' => false, 'amount' => 0.0, 'pct' => 0.0, 'aggregator_id' => $aggregatorId];
    }

    $pct                 = (float) $rate['commission_percentage'];
    $partnerCommission   = (float) $enrolment['commission_amount'];
    $aggregatorCommission = round($partnerCommission * $pct / 100, 2);

    db()->prepare("
        INSERT INTO aggregator_enrolment_records
          (partner_enrolment_id, partner_id, aggregator_id, import_batch_id,
           period_year, period_month,
           partner_commission_amount, aggregator_percentage, aggregator_commission_amount,
           commission_rate_id, effective_date_used, created_at)
        VALUES
          (:peid, :pid, :aid, :bid,
           :py, :pm,
           :pc, :pct, :ac,
           :crid, :ed, :ca)
    ")->execute([
        ':peid' => $enrolmentId,
        ':pid'  => (int) $enrolment['partner_id'],
        ':aid'  => $aggregatorId,
        ':bid'  => (int) $enrolment['import_batch_id'],
        ':py'   => (int) $enrolment['period_year'],
        ':pm'   => (int) $enrolment['period_month'],
        ':pc'   => $partnerCommission,
        ':pct'  => $pct,
        ':ac'   => $aggregatorCommission,
        ':crid' => (int) $rate['id'],
        ':ed'   => $effectiveDate,
        ':ca'   => now_dt(),
    ]);
    $settlementId = (int) db()->lastInsertId();

    // Ledger entry, only if the amount is non-zero
    if ($aggregatorCommission > 0) {
        aggregator_ledger_record(
            $aggregatorId,
            'COMMISSION_EARNED',
            $aggregatorCommission,
            'SETTLE-' . $settlementId,
            'Aggregator commission for ' . format_period((int) $enrolment['period_year'], (int) $enrolment['period_month']),
            ['year' => (int) $enrolment['period_year'], 'month' => (int) $enrolment['period_month']],
            ['settlement_id' => $settlementId, 'import_batch_id' => (int) $enrolment['import_batch_id']],
            'SUPER_ADMIN',
            $saId
        );
    }

    return [
        'settlement_id' => $settlementId,
        'created'       => true,
        'amount'        => $aggregatorCommission,
        'pct'           => $pct,
        'aggregator_id' => $aggregatorId,
    ];
}
/**
 * Resolve the aggregator linked to a specific enrolment, respecting the
 * NIN scope of the aggregator assignment.
 *
 * A partner's assignment with sub_account_id = NULL applies only to the
 * partner's own NIN.
 * A sub-account's assignment applies only to that sub-account's NIN.
 *
 * This means: linking an aggregator to a parent partner does not credit
 * the aggregator on the parent's sub-accounts, and vice versa.
 */
function aggregator_resolve_for_enrolment(array $enrolment): ?array
{
    if (empty($enrolment['partner_id'])) {
        return null;
    }
    $partnerId    = (int) $enrolment['partner_id'];
    $subAccountId = isset($enrolment['sub_account_id']) && $enrolment['sub_account_id'] !== null
        ? (int) $enrolment['sub_account_id']
        : null;

    $effectiveDate = (string) ($enrolment['effective_date_used'] ?? '');
    if ($effectiveDate === '') {
        $effectiveDate = sprintf('%04d-%02d-01', (int) $enrolment['period_year'], (int) $enrolment['period_month']);
    }

    // ---- 1. Dated lookup: an assignment in force on the enrolment's date
    if ($subAccountId === null) {
        $stmt = db()->prepare("
            SELECT a.id AS assignment_id, a.aggregator_id, ag.full_name AS aggregator_name
            FROM partner_aggregator_assignments a
            JOIN aggregators ag ON ag.id = a.aggregator_id
            WHERE a.partner_id = :pid
              AND a.sub_account_id IS NULL
              AND a.status = 'ACTIVE'
              AND a.effective_from <= :date_from
              AND (a.effective_to IS NULL OR a.effective_to >= :date_to)
            ORDER BY a.effective_from DESC, a.id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':pid'       => $partnerId,
            ':date_from' => $effectiveDate,
            ':date_to'   => $effectiveDate,
        ]);
    } else {
        $stmt = db()->prepare("
            SELECT a.id AS assignment_id, a.aggregator_id, ag.full_name AS aggregator_name
            FROM partner_aggregator_assignments a
            JOIN aggregators ag ON ag.id = a.aggregator_id
            WHERE a.partner_id = :pid
              AND a.sub_account_id = :sid
              AND a.status = 'ACTIVE'
              AND a.effective_from <= :date_from
              AND (a.effective_to IS NULL OR a.effective_to >= :date_to)
            ORDER BY a.effective_from DESC, a.id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':pid'       => $partnerId,
            ':sid'       => $subAccountId,
            ':date_from' => $effectiveDate,
            ':date_to'   => $effectiveDate,
        ]);
    }
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // ---- 2. Fallback: earliest assignment whose window starts after the
    //         requested date. Covers the "aggregator created today, enrolments
    //         are historical" case without needing a data update on every
    //         new aggregator.
    if ($subAccountId === null) {
        $stmt = db()->prepare("
            SELECT a.id AS assignment_id, a.aggregator_id, ag.full_name AS aggregator_name
            FROM partner_aggregator_assignments a
            JOIN aggregators ag ON ag.id = a.aggregator_id
            WHERE a.partner_id = :pid
              AND a.sub_account_id IS NULL
              AND a.status = 'ACTIVE'
              AND a.effective_from > :date_from
            ORDER BY a.effective_from ASC, a.id ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':pid'       => $partnerId,
            ':date_from' => $effectiveDate,
        ]);
    } else {
        $stmt = db()->prepare("
            SELECT a.id AS assignment_id, a.aggregator_id, ag.full_name AS aggregator_name
            FROM partner_aggregator_assignments a
            JOIN aggregators ag ON ag.id = a.aggregator_id
            WHERE a.partner_id = :pid
              AND a.sub_account_id = :sid
              AND a.status = 'ACTIVE'
              AND a.effective_from > :date_from
            ORDER BY a.effective_from ASC, a.id ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':pid'       => $partnerId,
            ':sid'       => $subAccountId,
            ':date_from' => $effectiveDate,
        ]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}
// =====================================================================
// NIN-scoped assignment helpers
// =====================================================================

/**
 * Resolve a NIN to its owner, whether a partner's own identity or a
 * sub-account. Only ACTIVE entities are returned.
 *
 * @return array|null
 *   ['type' => 'partner'|'sub_account', 'partner_id' => int,
 *    'sub_account_id' => int|null, 'display_name' => string,
 *    'partner_code' => string, 'partner_name' => string]
 */
function aggregator_nin_owner(string $nin): ?array
{
    $clean = preg_replace('/\D/', '', $nin);
    if (!preg_match('/^\d{11}$/', (string) $clean)) {
        return null;
    }

    // Partner's own NIN
    $stmt = db()->prepare("
        SELECT id, full_name, partner_code, status
        FROM partners
        WHERE nin_number = :n
        LIMIT 1
    ");
    $stmt->execute([':n' => $clean]);
    $p = $stmt->fetch();
    if ($p && $p['status'] === 'ACTIVE') {
        return [
            'type'           => 'partner',
            'partner_id'     => (int) $p['id'],
            'sub_account_id' => null,
            'display_name'   => (string) $p['full_name'],
            'partner_code'   => (string) $p['partner_code'],
            'partner_name'   => (string) $p['full_name'],
        ];
    }

    // Sub-account NIN
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
    if ($s && $s['sub_status'] === 'ACTIVE' && $s['partner_status'] === 'ACTIVE') {
        return [
            'type'           => 'sub_account',
            'partner_id'     => (int) $s['partner_id'],
            'sub_account_id' => (int) $s['id'],
            'display_name'   => (string) $s['full_name'],
            'partner_code'   => (string) $s['partner_code'],
            'partner_name'   => (string) $s['partner_name'],
        ];
    }

    return null;
}

/**
 * Link a NIN to an aggregator, closing any prior active assignment for the
 * same (partner_id, sub_account_id) tuple.
 *
 * Caller must manage the transaction if this is part of a larger operation.
 *
 * @return array{ok: bool, reason: string, assignment_id: int, type: ?string,
 *               partner_id: ?int, sub_account_id: ?int, display_name: ?string}
 */
function aggregator_assign_nin(
    int $aggregatorId,
    string $nin,
    string $effectiveFrom,
    int $saId,
    ?string $notes = null
): array {
    $owner = aggregator_nin_owner($nin);
    if (!$owner) {
        return [
            'ok' => false,
            'reason' => 'NIN is not registered to an ACTIVE partner or sub-account.',
            'assignment_id' => 0,
            'type' => null, 'partner_id' => null, 'sub_account_id' => null,
            'display_name' => null,
        ];
    }

    $partnerId    = (int) $owner['partner_id'];
    $subAccountId = $owner['sub_account_id'];

    // Close prior active assignment for the exact tuple
    if ($subAccountId === null) {
        db()->prepare("
            UPDATE partner_aggregator_assignments
            SET status       = 'SUPERSEDED',
                effective_to = DATE_SUB(:new_from_a, INTERVAL 1 DAY)
            WHERE partner_id = :pid_a
              AND sub_account_id IS NULL
              AND status = 'ACTIVE'
              AND effective_from < :new_from_b
        ")->execute([
            ':new_from_a' => $effectiveFrom,
            ':pid_a'      => $partnerId,
            ':new_from_b' => $effectiveFrom,
        ]);
    } else {
        db()->prepare("
            UPDATE partner_aggregator_assignments
            SET status       = 'SUPERSEDED',
                effective_to = DATE_SUB(:new_from_a, INTERVAL 1 DAY)
            WHERE partner_id = :pid_a
              AND sub_account_id = :sid_a
              AND status = 'ACTIVE'
              AND effective_from < :new_from_b
        ")->execute([
            ':new_from_a' => $effectiveFrom,
            ':pid_a'      => $partnerId,
            ':sid_a'      => $subAccountId,
            ':new_from_b' => $effectiveFrom,
        ]);
    }

    // Insert new assignment
    $stmt = db()->prepare("
        INSERT INTO partner_aggregator_assignments
          (partner_id, sub_account_id, aggregator_id, effective_from, effective_to,
           status, assigned_by_super_admin_id, assigned_at, notes)
        VALUES
          (:pid, :sid, :aid, :ef, NULL,
           'ACTIVE', :by, :at, :notes)
    ");
    $stmt->execute([
        ':pid'   => $partnerId,
        ':sid'   => $subAccountId,
        ':aid'   => $aggregatorId,
        ':ef'    => $effectiveFrom,
        ':by'    => $saId,
        ':at'    => now_dt(),
        ':notes' => $notes,
    ]);
    $assignId = (int) db()->lastInsertId();

    return [
        'ok' => true,
        'reason' => '',
        'assignment_id' => $assignId,
        'type' => $owner['type'],
        'partner_id' => $partnerId,
        'sub_account_id' => $subAccountId,
        'display_name' => $owner['display_name'],
    ];
}

/**
 * List every NIN currently linked to an aggregator.
 * Returns one row per active assignment, joined to its owner details.
 */
function aggregator_list_linked_nins(int $aggregatorId, bool $includeHistory = false): array
{
    $statusFilter = $includeHistory ? "1=1" : "a.status = 'ACTIVE'";

    $stmt = db()->prepare("
        SELECT a.id AS assignment_id, a.effective_from, a.effective_to, a.status AS assignment_status,
               a.sub_account_id,
               p.id AS partner_id, p.full_name AS partner_name, p.partner_code,
               p.nin_number AS partner_nin, p.status AS partner_status,
               s.id AS sub_account_id_ref, s.full_name AS sub_name, s.nin_number AS sub_nin,
               s.status AS sub_status
        FROM partner_aggregator_assignments a
        JOIN partners p ON p.id = a.partner_id
        LEFT JOIN partner_sub_accounts s ON s.id = a.sub_account_id
        WHERE a.aggregator_id = :aid
          AND $statusFilter
        ORDER BY a.status ASC, a.effective_from DESC, a.id DESC
    ");
    $stmt->execute([':aid' => $aggregatorId]);
    $rows = $stmt->fetchAll();

    // Normalise: each row becomes a uniform shape with type, nin, display_name
    $out = [];
    foreach ($rows as $r) {
        if ($r['sub_account_id'] === null) {
            $out[] = [
                'assignment_id'    => (int) $r['assignment_id'],
                'partner_id'       => (int) $r['partner_id'],
                'sub_account_id'   => null,
                'type'             => 'partner',
                'nin'              => (string) $r['partner_nin'],
                'display_name'     => (string) $r['partner_name'],
                'partner_name'     => (string) $r['partner_name'],
                'partner_code'     => (string) $r['partner_code'],
                'owner_status'     => (string) $r['partner_status'],
                'effective_from'   => (string) $r['effective_from'],
                'effective_to'     => $r['effective_to'],
                'assignment_status'=> (string) $r['assignment_status'],
            ];
        } else {
            $out[] = [
                'assignment_id'    => (int) $r['assignment_id'],
                'partner_id'       => (int) $r['partner_id'],
                'sub_account_id'   => (int) $r['sub_account_id'],
                'type'             => 'sub_account',
                'nin'              => (string) $r['sub_nin'],
                'display_name'     => (string) $r['sub_name'],
                'partner_name'     => (string) $r['partner_name'],
                'partner_code'     => (string) $r['partner_code'],
                'owner_status'     => (string) $r['sub_status'],
                'effective_from'   => (string) $r['effective_from'],
                'effective_to'     => $r['effective_to'],
                'assignment_status'=> (string) $r['assignment_status'],
            ];
        }
    }
    return $out;
}