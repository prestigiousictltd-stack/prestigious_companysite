<?php
/**
 * Server-side validation.
 */

declare(strict_types=1);

function validate(array $data, array $rules, array $labels = []): array
{
    $errors = [];
    foreach ($rules as $field => $fieldRules) {
        $label = $labels[$field] ?? ucwords(str_replace(['_', '-'], ' ', $field));
        $value = $data[$field] ?? null;

        foreach ($fieldRules as $rule) {
            $param = null;
            if (strpos($rule, ':') !== false) {
                [$rule, $param] = explode(':', $rule, 2);
            }

            $error = validate_rule($rule, $value, $param, $data, $label);
            if ($error !== null) {
                $errors[$field] = $error;
                break;
            }
        }
    }
    return $errors;
}

function validate_rule(string $rule, $value, ?string $param, array $all, string $label): ?string
{
    $isBlank = ($value === null || $value === '' || (is_array($value) && empty($value)));

    if ($rule === 'required') {
        return $isBlank ? "$label is required." : null;
    }
    if ($rule === 'nullable') {
        return null;
    }
    if ($isBlank) {
        return null;
    }

    switch ($rule) {
        case 'string':
            return is_string($value) ? null : "$label must be text.";

        case 'email':
            return filter_var((string) $value, FILTER_VALIDATE_EMAIL)
                ? null : "$label must be a valid email address.";

        case 'int':
            return preg_match('/^-?\d+$/', (string) $value)
                ? null : "$label must be a whole number.";

        case 'decimal':
            return is_numeric((string) $value)
                ? null : "$label must be a number.";

        /*
         * min / max — threshold rules.
         * If the value looks numeric, compare as a number (useful for
         * amounts, quantities, percentages).
         * Otherwise fall back to string length. This dual behaviour is
         * intentional and matches the historical semantics.
         * For text fields that may contain all-digit values (phone numbers,
         * account numbers), use min_len / max_len instead.
         */
        case 'min':
            $n = (int) $param;
            if (is_numeric($value)) {
                return ((float) $value) >= $n ? null : "$label must be at least $n.";
            }
            if (is_string($value)) {
                return mb_strlen($value) >= $n ? null : "$label must be at least $n characters.";
            }
            if (is_array($value)) {
                return count($value) >= $n ? null : "$label must contain at least $n items.";
            }
            return null;

        case 'max':
            $n = (int) $param;
            if (is_numeric($value)) {
                return ((float) $value) <= $n ? null : "$label must be at most $n.";
            }
            if (is_string($value)) {
                return mb_strlen($value) <= $n ? null : "$label must be at most $n characters.";
            }
            if (is_array($value)) {
                return count($value) <= $n ? null : "$label must contain at most $n items.";
            }
            return null;

        /*
         * min_len / max_len — pure character-count rules.
         * Always measure mb_strlen, never numeric value. Use these for any
         * text field whose value might be all digits (phone, account number,
         * postal code, ID strings, etc.).
         */
        case 'min_len':
            $n = (int) $param;
            return mb_strlen((string) $value) >= $n
                ? null : "$label must be at least $n characters.";

        case 'max_len':
            $n = (int) $param;
            return mb_strlen((string) $value) <= $n
                ? null : "$label must be at most $n characters.";

        case 'between':
            [$a, $b] = array_pad(explode(',', (string) $param), 2, 0);
            $n = (float) $value;
            return ($n >= (float) $a && $n <= (float) $b)
                ? null : "$label must be between $a and $b.";

        case 'regex':
            return preg_match($param, (string) $value)
                ? null : "$label is not in the expected format.";

        case 'in':
            $allowed = array_map('trim', explode(',', (string) $param));
            return in_array((string) $value, $allowed, true)
                ? null : "$label contains an invalid value.";

        case 'date':
            $d = DateTime::createFromFormat('Y-m-d', (string) $value);
            return ($d && $d->format('Y-m-d') === (string) $value)
                ? null : "$label must be a valid date (YYYY-MM-DD).";

        case 'phone_ng':
            $clean = preg_replace('/\D/', '', (string) $value);
            if (preg_match('/^0[7-9][01]\d{8}$/', $clean)) return null;
            if (preg_match('/^234[7-9][01]\d{8}$/', $clean)) return null;
            return "$label must be a valid Nigerian phone number.";

        case 'nin':
            $clean = preg_replace('/\D/', '', (string) $value);
            return preg_match('/^\d{11}$/', $clean)
                ? null : "$label must be exactly 11 digits.";

        case 'password':
            $min = (int) setting('security_password_min_length', 8);
            if (mb_strlen((string) $value) < $min) {
                return "$label must be at least $min characters.";
            }
            $hasUpper = (bool) preg_match('/[A-Z]/', (string) $value);
            $hasLower = (bool) preg_match('/[a-z]/', (string) $value);
            $hasDigit = (bool) preg_match('/\d/', (string) $value);
            if (!$hasUpper || !$hasLower || !$hasDigit) {
                return "$label must contain upper case, lower case and a number.";
            }
            return null;

        case 'same':
            return ((string) $value === (string) ($all[$param] ?? null))
                ? null : "$label does not match.";

        case 'confirmed':
            $conf = $all[$param ?? ($label . '_confirmation')] ?? $all['password_confirmation'] ?? null;
            return ((string) $value === (string) $conf)
                ? null : "$label confirmation does not match.";
    }
    return null;
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_get(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
}