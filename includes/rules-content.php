<?php
/**
 * NIN Enrolment Partner Rules and Regulations.
 *
 * Single source of truth for the modal and the standalone page.
 * To update the rules: edit this file, bump RULES_VERSION below.
 * No database change needed.
 */

declare(strict_types=1);

const RULES_VERSION = '1.0';
const RULES_EFFECTIVE_DATE = '2026-10-15';

function partner_rules(): array
{
    return [
        'title'   => '10 Rules and Regulations Governing NIN Enrolment Partners',
        'rules'   => [
            ['n' => 1,  'title' => 'No Infraction of Any Kind',
             'body'  => 'Partners shall not commit any infraction of any kind in the course of operating an enrolment station.'],
            ['n' => 2,  'title' => 'No Extortion, Bribery, or Corruption',
             'body'  => 'Partners shall not extort applicants, solicit or accept bribes, or engage in any act of corruption in connection with enrolment services.'],
            ['n' => 3,  'title' => 'Strict Confidentiality and Data Protection',
             'body'  => 'All personal data encountered during enrolment shall be treated as strictly confidential and protected in line with applicable data protection requirements.'],
            ['n' => 4,  'title' => 'Prohibition of Identity Fraud and Duplicate Enrolment',
             'body'  => 'Partners shall not facilitate, participate in, or permit identity fraud, impersonation, or duplicate enrolment of any individual.'],
            ['n' => 5,  'title' => 'Authorized Use of Enrolment Equipment and Systems',
             'body'  => 'Enrolment equipment and systems shall be used only for their authorized purpose and in accordance with the instructions issued by the company.'],
            ['n' => 6,  'title' => 'Accuracy and Integrity of Enrolment Records',
             'body'  => 'Partners shall ensure that every enrolment record they generate is accurate, complete, and free from manipulation.'],
            ['n' => 7,  'title' => 'Compliance with NIMC Authorization and Company Directives',
             'body'  => 'Partners shall comply with all authorizations granted by NIMC and with every directive issued by Prestigious ICT Investment Limited.'],
            ['n' => 8,  'title' => 'Prohibition of Unauthorized Collaboration and Transfer of Authority',
             'body'  => 'Partners shall not collaborate with unauthorized parties or transfer their authority to any other person or entity without written approval.'],
            ['n' => 9,  'title' => 'Transparency',
             'body'  => 'Partners shall conduct their operations transparently and provide truthful information to the company and to applicants at all times.'],
            ['n' => 10, 'title' => 'Mandatory Reporting and Consequences of Violation',
             'body'  => 'Partners shall promptly report any breach or suspected breach of these rules. Violations may result in action under the partnership agreement and applicable law.'],
        ],
        'declaration' => 'All subordinate partners operating under PRESTIGIOUS ICT INVESTMENT LIMITED shall be required to read, understand, and comply with these rules as part of their operational responsibilities. Failure to comply may result in action under the partnership agreement and applicable law.',
    ];
}

function partner_rules_version(): string
{
    return RULES_VERSION;
}

function partner_rules_effective_date(): string
{
    return RULES_EFFECTIVE_DATE;
}

/** Hash of the current rules text. Used to detect drift between form load and submit. */
function partner_rules_hash(): string
{
    $d = partner_rules();
    return hash('sha256', $d['title'] . "\n" . json_encode($d['rules'], JSON_UNESCAPED_UNICODE) . "\n" . $d['declaration']);
}
