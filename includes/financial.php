<?php
/**
 * Partner financial ledger helpers.
 * All balance arithmetic is derived from partner_financial_transactions.
 * There is no stored editable balance column anywhere.
 */

declare(strict_types=1);

/**
 * Compute the current available balance for a partner.
 * By convention, the `amount` column is signed:
 *   COMMISSION_EARNED     → positive
 *   WITHDRAWAL_RESERVED   → negative (money held)
 *   WITHDRAWAL_REVERSED   → positive (reservation released)
 *   WITHDRAWAL_PAID       → negative (money gone)
 *   ADJUSTMENT / REVERSAL → signed
 * Available balance = SUM(amount).
 */
function partner_available_balance(int $partnerId, bool $forUpdate = false): float
{
    $sql = 'SELECT COALESCE(SUM(amount), 0) FROM partner_financial_transactions WHERE partner_id = :pid';
    if ($forUpdate) {
        // Note: aggregate + FOR UPDATE is not fully portable; the caller
        // should lock the partners row to serialise balance-affecting writes.
        $sql .= ' FOR UPDATE';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([':pid' => $partnerId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * Sum of paid withdrawals (positive number).
 */
function partner_total_withdrawn(int $partnerId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(-amount), 0)
        FROM partner_financial_transactions
        WHERE partner_id = :pid AND type = 'WITHDRAWAL_PAID'
    ");
    $stmt->execute([':pid' => $partnerId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * Sum of commission earned (positive number).
 */
function partner_total_commission(int $partnerId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM partner_financial_transactions
        WHERE partner_id = :pid AND type = 'COMMISSION_EARNED'
    ");
    $stmt->execute([':pid' => $partnerId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * Sum of amounts reserved by pending / approved / processing withdrawals.
 */
function partner_total_reserved(int $partnerId): float
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM withdrawals
        WHERE partner_id = :pid AND status IN ('PENDING','APPROVED','PROCESSING')
    ");
    $stmt->execute([':pid' => $partnerId]);
    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * Sum of amounts reserved for withdrawals in a specific bucket.
 * Pass $subAccountId = null for the partner's own NIN bucket.
 */
function partner_bucket_reserved(int $partnerId, ?int $subAccountId): float
{
    if ($subAccountId === null) {
        $stmt = db()->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM withdrawals
            WHERE partner_id = :pid
              AND sub_account_id IS NULL
              AND status IN ('PENDING','APPROVED','PROCESSING')
        ");
        $stmt->execute([':pid' => $partnerId]);
    } else {
        $stmt = db()->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM withdrawals
            WHERE partner_id = :pid
              AND sub_account_id = :sid
              AND status IN ('PENDING','APPROVED','PROCESSING')
        ");
        $stmt->execute([':pid' => $partnerId, ':sid' => $subAccountId]);
    }
    return round((float) $stmt->fetchColumn(), 2);
}


/**
 * Append a ledger entry. Assumes the caller is inside a transaction and has
 * already locked the partner row (SELECT id FROM partners WHERE id = ? FOR UPDATE).
 *
 * @param float  $amountSigned  signed per the convention above.
 * @param array  $related       optional keys: enrolment_id, withdrawal_id, import_batch_id
 * @param array  $period        optional keys: year, month
 */
function ledger_record(
    int $partnerId,
    string $type,
    float $amountSigned,
    ?string $reference = null,
    ?string $description = null,
    array $period = [],
    array $related = [],
    string $actorRole = 'SYSTEM',
    ?int $actorId = null,
    ?int $subAccountId = null
): int {
    if (!in_array($type, ['COMMISSION_EARNED','WITHDRAWAL_RESERVED','WITHDRAWAL_PAID','WITHDRAWAL_REVERSED','ADJUSTMENT','REVERSAL'], true)) {
        throw new InvalidArgumentException('Invalid ledger type: ' . $type);
    }

    $prev = partner_available_balance($partnerId);
    $new  = round($prev + $amountSigned, 2);

    $txId = 'TX-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        $stmt = db()->prepare('
        INSERT INTO partner_financial_transactions
          (transaction_id, partner_id, sub_account_id, reference, amount, type,
           period_year, period_month, description,
           previous_balance, new_balance,
           related_enrolment_id, related_withdrawal_id, related_import_batch_id,
           created_by_role, created_by_id, created_at)
        VALUES
          (:tid, :pid, :sid, :ref, :amt, :type,
           :py, :pm, :desc,
           :prev, :new,
           :reid, :rwid, :rbid,
           :cbr, :cbi, :ca)
    ');
    $stmt->execute([
        ':tid'  => $txId,
        ':pid'  => $partnerId,
        ':sid'  => $subAccountId,
        ':ref'  => $reference,
        ':amt'  => $amountSigned,
        ':type' => $type,
        ':py'   => $period['year'] ?? null,
        ':pm'   => $period['month'] ?? null,
        ':desc' => $description,
        ':prev' => $prev,
        ':new'  => $new,
        ':reid' => $related['enrolment_id'] ?? null,
        ':rwid' => $related['withdrawal_id'] ?? null,
        ':rbid' => $related['import_batch_id'] ?? null,
        ':cbr'  => $actorRole,
        ':cbi'  => $actorId,
        ':ca'   => now_dt(),
    ]);

    return (int) db()->lastInsertId();
}