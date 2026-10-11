<?php
/**
 * Static content data for the public site.
 */

if (!function_exists('public_services')) {
    function public_services(): array
    {
        return [

            // =========================================================
            // JAMB SERVICES
            // =========================================================
            'jamb-services' => [
                'slug'    => 'jamb-services',
                'title'   => 'JAMB Services',
                'short'   => 'Computer-based test preparation, registration support and result services for JAMB candidates.',
                'icon'    => 'file',
                'summary' => 'Registration support, CBT practice and result services for candidates preparing for the Unified Tertiary Matriculation Examination (UTME) and Direct Entry.',
                'body'    => [
                    'Overview',
                    'Our JAMB Services support candidates through the full cycle of the Joint Admissions and Matriculation Board (JAMB) process — from profile creation and UTME registration through to post-examination result services.',
                    'The service is delivered through our computer-based facilities in Kano, where candidates can complete their registration, sit practice sessions, and access result-related support in a controlled environment.',
                    'What this service covers',
                    'Profile creation and correction, UTME and Direct Entry registration, subject combination guidance, CBT practice sessions, examination-day support, and result checking services.',
                    'Whether you are a first-time applicant, a candidate preparing for a re-sit, or a school coordinating a group of candidates, our team can assist at each stage.',
                    'How it works',
                    'Walk in or contact us in advance to book a session. Bring a valid means of identification and the details required for your registration. Our staff will guide you through the process, verify your information, and confirm your submission.',
                    'Practice sessions are available on our computer-based facilities, giving candidates the opportunity to familiarise themselves with the examination environment before the actual test.',
                    'What to bring',
                    'A valid means of identification, your JAMB profile details (if you have already created one), a recent passport photograph, and the correct registration fee. Contact us before your visit if you are unsure what applies to your situation.',
                    'Availability',
                    'Service availability is coordinated centrally and may vary during peak registration windows. Contact us to confirm current turnaround times and to book a session.',
                ],
            ],

            // =========================================================
            // WORK FORCE SERVICES
            // =========================================================
            'workforce-services' => [
                'slug'    => 'workforce-services',
                'title'   => 'Work Force Services',
                'short'   => 'Facility and technical support for recruitment, examination and screening of candidates.',
                'icon'    => 'users',
                'summary' => 'Facilities and technical support for the examination and screening stages of client recruitment processes, delivered in partnership with Workforce.',
                'body'    => [
                    'Overview',
                    'Workforce is a professional consultancy firm specialising in staffing and recruitment services for various Ministries, Government Agencies and private companies. Its responsibilities include the selection, examination, screening, assessment and interviewing of prospective candidates in accordance with the requirements of its clients.',
                    'As part of its recruitment and assessment processes, Workforce partners with Prestigious ICT Investment Limited to provide suitable facilities and technical support for the examination and screening of candidates.',
                    'What this service covers',
                    'Computer-based examination facilities, technical invigilation support, candidate verification and identity checks, on-site coordination, and secure handling of examination materials.',
                    'Our role is to ensure that every examination and screening session runs reliably, safely and to the standard required by Workforce and their client organisations.',
                    'How it works',
                    'Workforce coordinates the recruitment process and schedules candidate sessions. Prestigious ICT Investment Limited provides the physical venue, networked workstations, and technical team required to deliver the examination or screening component.',
                    'Results and candidate data are handled in line with agreed procedures and applicable data protection requirements.',
                    'Data protection',
                    'Candidate information handled during screening and examination is treated as confidential. Access is restricted to authorised personnel, and every session is logged.',
                    'Contact',
                    'For enquiries about an upcoming screening or examination, contact the coordinating organisation. For enquiries about the venue or facilities, contact us directly.',
                ],
            ],

            // =========================================================
            // MIVA OPEN UNIVERSITY SUPPORT
            // =========================================================
            'miva-open-university' => [
                'slug'    => 'miva-open-university',
                'title'   => 'MIVA Open University Support',
                'short'   => 'Approved facilities in Kano for MIVA Open University students\u2019 academic activities and examinations.',
                'icon'    => 'graduation',
                'summary' => 'Facilities and infrastructure in Kano State supporting MIVA Open University students\u2019 learning and examination activities.',
                'body'    => [
                    'Overview',
                    'MIVA Open University is a university licensed by the National Universities Commission (NUC). For students based in Kano State, the University utilises the facilities of Prestigious ICT Investment Limited to support students\u2019 academic activities, including examinations and learning.',
                    'Prestigious ICT Investment Limited provides the necessary facilities and infrastructure to enable MIVA Open University students in Kano to conveniently participate in their academic programmes and examinations in line with applicable standards and requirements.',
                    'What this service covers',
                    'Approved examination venues, computer-based learning and assessment facilities, technical support during examination sessions, student verification and identity checks, and coordination with the University for scheduling.',
                    'Our facilities are maintained to the standard required by the University and are operated in line with the University\u2019s academic calendar.',
                    'How it works',
                    'MIVA Open University enrols students and schedules their academic and examination activities. Students based in Kano attend their scheduled sessions at our centre, where our team ensures the facilities, connectivity and technical support are ready.',
                    'Attendance, scheduling and examination logistics are coordinated between the University and Prestigious ICT Investment Limited. Students should follow the University\u2019s communication for their specific session details.',
                    'Who this is for',
                    'Students of MIVA Open University who are resident in or near Kano State and whose academic activities have been scheduled at our centre.',
                    'Contact',
                    'For enquiries about your programme, enrolment or examination schedule, contact MIVA Open University directly. For enquiries about the venue or facilities, contact us.',
                ],
            ],

            // =========================================================
            // EXISTING SERVICES (unchanged)
            // =========================================================
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