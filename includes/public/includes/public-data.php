<?php
/**
 * Static content data for the public site.
 * No government accreditations or certifications are asserted here.
 */

if (!function_exists('public_services')) {
    function public_services(): array
    {
        return [
            'nin-enrolment' => [
                'slug'    => 'nin-enrolment',
                'title'   => 'NIN Enrolment',
                'short'   => 'Enrolment support for the National Identification Number process at partner stations.',
                'icon'    => 'fingerprint',
                'summary' => 'Walk-in and assisted NIN enrolment services delivered through our partner station network.',
                'body'    => [
                    'What this service covers',
                    'Our NIN Enrolment service supports individuals through the enrolment process for the National Identification Number, delivered through our partner station network across Nigeria.',
                    'The service is designed for individuals who need first-time enrolment, pre-enrolment assistance, or guidance on the documents required to complete the process.',
                    'How it works',
                    'Partners operate enrolment stations that are onboarded and supported by Prestigious ICT Investment Limited. Each station records enrolment activity against a defined reporting period, which is aggregated into the partner portal.',
                    'What to bring',
                    'Applicants should carry a valid means of identification and any supporting documents required by the enrolment process. Specific requirements may vary; contact your nearest station for guidance before your visit.',
                    'Reporting to partners',
                    'Every enrolment station has access to a Partner Portal where enrolment statistics for the current and previous periods are available, together with commission statements.',
                ],
            ],
            'nin-verification' => [
                'slug'    => 'nin-verification',
                'title'   => 'NIN Verification',
                'short'   => 'Verification of NIN details for individuals and organisations through approved channels.',
                'icon'    => 'shield-check',
                'summary' => 'Identity verification support services for organisations that need to confirm NIN details.',
                'body'    => [
                    'Overview',
                    'Our NIN Verification service supports organisations that need to confirm identity details against the National Identification Number. The service is delivered through approved verification channels.',
                    'Who it is for',
                    'Businesses that need to verify customer identity as part of onboarding, KYC, or compliance processes will find this service useful.',
                    'How verification is handled',
                    'Verification requests are processed through the appropriate channel and results are returned to the requesting organisation. All handling is done in line with applicable data protection principles.',
                    'Data protection',
                    'Verification data is treated as sensitive. Access is restricted, and our platform logs every verification action for audit purposes. NIN values are masked in all routine displays.',
                ],
            ],
            'digital-services' => [
                'slug'    => 'digital-services',
                'title'   => 'Digital Services',
                'short'   => 'Practical digital services that help individuals and businesses get routine tasks done.',
                'icon'    => 'globe',
                'summary' => 'A growing catalogue of digital services delivered through partner stations.',
                'body'    => [
                    'A broad catalogue',
                    'Our Digital Services cover the practical, day-to-day tasks that individuals and small businesses need help with. Services include document processing, online form submission, printing and scanning, and general digital assistance.',
                    'Delivered through stations',
                    'These services are delivered through our partner station network. Each station is operated by a trained partner who has been onboarded onto our platform.',
                    'Consistent service standards',
                    'Service standards, pricing and turnaround expectations are set centrally and applied consistently across all stations.',
                ],
            ],
            'education-services' => [
                'slug'    => 'education-services',
                'title'   => 'Education / e-Services',
                'short'   => 'Support for admission letter processing, result services, and other education-related tasks.',
                'icon'    => 'graduation',
                'summary' => 'Practical assistance with education-related digital tasks.',
                'body'    => [
                    'Overview',
                    'Our Education Services support students, parents and schools with the digital tasks that come up during the admissions cycle and beyond.',
                    'Typical services',
                    'This includes assistance with admission letter processing, post-UTME registration, result checking and similar tasks. The exact catalogue may vary by station and by season.',
                    'Availability',
                    'Service availability is coordinated centrally so that stations can advertise accurate turnaround times to applicants.',
                ],
            ],
            'technology-solutions' => [
                'slug'    => 'technology-solutions',
                'title'   => 'Technology Solutions',
                'short'   => 'Technology and infrastructure support for partner-operated stations.',
                'icon'    => 'cpu',
                'summary' => 'Technology enablement for partner stations and service delivery.',
                'body'    => [
                    'Purpose-built for partners',
                    'Partners operate enrolment and service stations under our brand and platform. We provide the technology, reporting tools and support that make those stations work.',
                    'Platform features',
                    'Partners get a private portal with enrolment statistics, commission statements and withdrawal tools. The platform is built to handle sensitive identity data with care.',
                    'Continuous improvement',
                    'The technology stack behind the partner network is maintained and improved on an ongoing basis.',
                ],
            ],
        ];
    }

    function public_service_icon_svg(string $name, int $size = 26): string
    {
        $attrs = 'width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
        return match ($name) {
            'fingerprint'   => '<svg ' . $attrs . '><path d="M12 10a2 2 0 0 0-2 2v3.5a2.5 2.5 0 0 0 5 0V12a2 2 0 0 0-2-2z"/><path d="M6 12a6 6 0 0 1 12 0v2"/><path d="M4 12a8 8 0 0 1 16 0v3"/><path d="M17 20a9 9 0 0 0 1-4"/><path d="M6 20a11 11 0 0 1-1-8"/><path d="M10 20a8 8 0 0 1-1-4"/></svg>',
            'shield-check'  => '<svg ' . $attrs . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
            'globe'         => '<svg ' . $attrs . '><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg>',
            'graduation'    => '<svg ' . $attrs . '><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1.5 3 3 6 3s6-1.5 6-3v-5"/></svg>',
            'cpu'           => '<svg ' . $attrs . '><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/></svg>',
            'chart'         => '<svg ' . $attrs . '><path d="M3 3v18h18"/><path d="m7 14 3-3 4 4 5-6"/></svg>',
            'users'         => '<svg ' . $attrs . '><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'lock'          => '<svg ' . $attrs . '><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            'map-pin'       => '<svg ' . $attrs . '><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>',
            'phone'         => '<svg ' . $attrs . '><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.6a2 2 0 0 1-.5 2.1L8 9.7a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.8.3 1.7.6 2.6.7a2 2 0 0 1 1.7 2z"/></svg>',
            'mail'          => '<svg ' . $attrs . '><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 7 10-7"/></svg>',
            'clock'         => '<svg ' . $attrs . '><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
            'check'         => '<svg ' . $attrs . '><path d="m3 12 5 5L21 6"/></svg>',
            'file'          => '<svg ' . $attrs . '><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8"/></svg>',
            default         => '<svg ' . $attrs . '><circle cx="12" cy="12" r="9"/></svg>',
        };
    }
}