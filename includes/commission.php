<?php
declare(strict_types=1);

/**
 * Resolve the commission rate for a partner on a given date.
 * Returns the rate row or null if no rate applies.
 *
 * NOTE: with PDO::ATTR_EMULATE_PREPARES = false, every named placeholder
 * must appear EXACTLY ONCE in a statement. Do not reuse :d or any name.
 */
function commission_resolve_rate(int $partnerId, string $effectiveDate): ?array
{
    // 1. Try the effective-dated lookup
    $stmt = db()->prepare("
        SELECT * FROM partner_commission_rates
        WHERE partner_id = :pid
          AND status = 'ACTIVE'
          AND effective_from <= :date_from
          AND (effective_to IS NULL OR effective_to >= :date_to)
        ORDER BY effective_from DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':pid'       => $partnerId,
        ':date_from' => $effectiveDate,
        ':date_to'   => $effectiveDate,
    ]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // 2. Fallback: the earliest ACTIVE rate for this partner whose start is
    //    after the requested date. This covers the onboarding case where a
    //    rate is created today but imports reference earlier periods.
    $stmt = db()->prepare("
        SELECT * FROM partner_commission_rates
        WHERE partner_id = :pid
          AND status = 'ACTIVE'
          AND effective_from > :date_from
        ORDER BY effective_from ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([
        ':pid'       => $partnerId,
        ':date_from' => $effectiveDate,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Compute gross revenue and commission for a partner's enrolment figures.
 * Returns ['gross_revenue'=>float, 'commission_amount'=>float, 'pct'=>float, 'rate_id'=>int|null].
 */
function commission_compute(int $ninGenerated, float $revenuePerNin, ?array $rateRow): array
{
    if ($rateRow === null) {
        throw new RuntimeException('No applicable commission rate');
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
 * Whether a new effective-dated rate would overlap an existing ACTIVE range.
 */
function commission_would_overlap(int $partnerId, string $from, ?string $to, ?int $exceptId = null): bool
{
    $sql = "SELECT COUNT(*) FROM partner_commission_rates
            WHERE partner_id = :pid
              AND status = 'ACTIVE'
              AND (:range_from <= COALESCE(effective_to, '9999-12-31'))
              AND (COALESCE(:range_to, '9999-12-31') >= effective_from)";
    $params = [
        ':pid'        => $partnerId,
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
 * Attach orphan enrolment records to a newly approved partner and settle
 * the commission that was deferred while the partner was unknown.
 */
function commission_settle_orphans(int $partnerId, string $ninRaw, int $saId): array
{
    require_once PICT_ROOT . '/includes/financial.php';

    $ninRaw = preg_replace('/\D/', '', $ninRaw);
    if ($ninRaw === '') {
        return ['settled' => 0, 'commission' => 0.0, 'total_nin' => 0];
    }

    $stmt = db()->prepare("
        SELECT * FROM partner_enrolment_records
        WHERE partner_id IS NULL
          AND partner_nin_raw = :nin
        ORDER BY period_year ASC, period_month ASC, id ASC
    ");
    $stmt->execute([':nin' => $ninRaw]);
    $orphans = $stmt->fetchAll();

    if (empty($orphans)) {
        return ['settled' => 0, 'commission' => 0.0, 'total_nin' => 0];
    }

    $totalCommission = 0.0;
    $totalNin = 0;
    $settled = 0;

    foreach ($orphans as $rec) {
        $effectiveDate = (string) $rec['effective_date_used'];
        $rate = commission_resolve_rate($partnerId, $effectiveDate);

        if (!$rate) {
            $fallback = db()->prepare("
                SELECT * FROM partner_commission_rates
                WHERE partner_id = :pid AND status = 'ACTIVE'
                ORDER BY effective_from DESC, id DESC
                LIMIT 1
            ");
            $fallback->execute([':pid' => $partnerId]);
            $rate = $fallback->fetch() ?: null;
        }

        if (!$rate) {
            continue;
        }

        $pct = (float) $rate['commission_percentage'];
        $revenuePerNin = (float) $rec['revenue_per_nin'];
        $nin = (int) $rec['total_nin_generated'];
        $gross = round($nin * $revenuePerNin, 2);
        $commission = round($gross * $pct / 100, 2);

        db()->prepare("
            UPDATE partner_enrolment_records SET
                partner_id = :pid,
                commission_percentage = :pct,
                commission_rate_id = :rid,
                gross_revenue = :gr,
                commission_amount = :ca
            WHERE id = :id
        ")->execute([
            ':pid' => $partnerId,
            ':pct' => $pct,
            ':rid' => (int) $rate['id'],
            ':gr'  => $gross,
            ':ca'  => $commission,
            ':id'  => (int) $rec['id'],
        ]);

        if ($commission > 0) {
            ledger_record(
                $partnerId,
                'COMMISSION_EARNED',
                $commission,
                'SETTLE-' . $rec['id'],
                'Retroactive commission for ' . format_period((int) $rec['period_year'], (int) $rec['period_month']),
                ['year' => (int) $rec['period_year'], 'month' => (int) $rec['period_month']],
                ['enrolment_id' => (int) $rec['id'], 'import_batch_id' => (int) $rec['import_batch_id']],
                'SUPER_ADMIN',
                $saId
            );
        }

        $totalCommission += $commission;
        $totalNin += $nin;
        $settled++;
    }

    return [
        'settled'    => $settled,
        'commission' => round($totalCommission, 2),
        'total_nin'  => $totalNin,
    ];
}