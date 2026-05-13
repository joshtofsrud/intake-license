#!/bin/bash
# ============================================================================
# patch-58-legal-pages.sh
# ----------------------------------------------------------------------------
# Adds five legal pages to the marketing CMS:
#   - /terms              Terms of Service
#   - /privacy            Privacy Policy
#   - /cookies            Cookie Policy
#   - /acceptable-use     Acceptable Use Policy
#   - /sub-processors     Sub-processor list
#
# Adds a new 'legal_doc' section type with its own Blade partial for clean
# long-form prose rendering (h2 headings, paragraphs, bulleted lists,
# optional TOC).
#
# Footer wiring is INCLUDED but the legal links are commented out by default —
# you flip a single block of HTML to live them after the LLC is registered
# and the email forwards are set up.
#
# IMPORTANT: This is template content. NOT legal advice. Have an attorney
# in WA review before accepting real money. ~$300 of attorney time is a
# defensible investment before launch.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "database/seeders/PlatformMarketingSeeder.php" ]; then
  echo "ERROR: PlatformMarketingSeeder.php not found." >&2
  exit 1
fi

# ─── 1. Register legal_doc in DEFAULTS ─────────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/PageBuilderController.php")
s = p.read_text()

old = "        'screen_showcase'  => ['eyebrow'=>'','step_num'=>1,'heading'=>'Step heading','body'=>'Short body for this step.','points'=>[],'desktop_label'=>'Desktop','desktop_lines'=>[],'mobile_label'=>'Mobile','mobile_lines'=>[],'mobile_note'=>'','flip'=>false],\n    ];"

new = """        'screen_showcase'  => ['eyebrow'=>'','step_num'=>1,'heading'=>'Step heading','body'=>'Short body for this step.','points'=>[],'desktop_label'=>'Desktop','desktop_lines'=>[],'mobile_label'=>'Mobile','mobile_lines'=>[],'mobile_note'=>'','flip'=>false],
        'legal_doc'        => ['doc_title'=>'Document title','effective_date'=>'','updated_date'=>'','intro_paragraph'=>'','show_toc'=>true,'sections'=>[['heading'=>'Section heading','blocks'=>[['type'=>'paragraph','text'=>'']]]]],
    ];"""

if "'legal_doc'" in s:
    print("    SKIP legal_doc — already registered in DEFAULTS")
elif old not in s:
    raise SystemExit("ABORT legal_doc: screen_showcase anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — legal_doc registered in DEFAULTS")
PYEOF

# ─── 2. Create legal_doc.blade.php partial ─────────────────────────────
if [ -f "resources/views/marketing/sections/legal_doc.blade.php" ]; then
    echo "    SKIP partial — legal_doc.blade.php already exists"
else
cat > resources/views/marketing/sections/legal_doc.blade.php <<'BLADE'
{{-- 
    Legal document section — long-form prose with optional TOC.
--}}
@php
    $sections = $c['sections'] ?? [];
    $showToc  = !empty($c['show_toc']);
    
    $slugify = function(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    };
@endphp

<style>
.legal-wrap { max-width: 760px; margin: 0 auto; padding: clamp(48px,6vw,80px) 24px; }
.legal-title { font-size: clamp(32px,4vw,44px); font-weight: 700; letter-spacing: -.02em; line-height: 1.15; margin: 0 0 18px; }
.legal-meta { color: var(--mk-muted); font-size: 14px; margin: 0 0 36px; padding-bottom: 24px; border-bottom: .5px solid var(--mk-border); }
.legal-meta-item { margin-right: 20px; }
.legal-intro { font-size: 17px; line-height: 1.7; color: var(--mk-text); margin: 0 0 40px; }
.legal-toc { background: rgba(255,255,255,.02); border: .5px solid var(--mk-border); border-radius: 12px; padding: 24px 28px; margin-bottom: 56px; }
.legal-toc-label { font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: var(--mk-dim); font-weight: 600; margin-bottom: 14px; }
.legal-toc ol { margin: 0; padding: 0; list-style: none; counter-reset: toc; }
.legal-toc li { counter-increment: toc; font-size: 14px; padding: 6px 0; display: flex; gap: 12px; }
.legal-toc li::before { content: counter(toc) "."; color: var(--mk-dim); flex-shrink: 0; min-width: 24px; }
.legal-toc a { color: var(--mk-text); text-decoration: none; transition: color .12s; }
.legal-toc a:hover { color: var(--mk-accent); }
.legal-section { margin-bottom: 48px; counter-increment: section; }
.legal-section-h2 { font-size: 22px; font-weight: 700; letter-spacing: -.01em; line-height: 1.3; margin: 0 0 18px; scroll-margin-top: 80px; }
.legal-section-h2::before { content: counter(section) ". "; color: var(--mk-dim); font-weight: 500; }
.legal-section-h3 { font-size: 16px; font-weight: 600; margin: 24px 0 10px; color: var(--mk-text); }
.legal-section p { font-size: 15px; line-height: 1.75; color: var(--mk-text); margin: 0 0 14px; }
.legal-section ul { font-size: 15px; line-height: 1.7; color: var(--mk-text); margin: 0 0 14px; padding-left: 22px; }
.legal-section li { margin-bottom: 6px; }
.legal-section a { color: var(--mk-accent); text-decoration: underline; text-decoration-thickness: .5px; text-underline-offset: 3px; }
.legal-wrap { counter-reset: section; }
</style>

<div class="legal-wrap">
    @if(!empty($c['doc_title']))
    <h1 class="legal-title">{{ $c['doc_title'] }}</h1>
    @endif

    @if(!empty($c['effective_date']) || !empty($c['updated_date']))
    <p class="legal-meta">
        @if(!empty($c['effective_date']))
            <span class="legal-meta-item"><strong>Effective:</strong> {{ $c['effective_date'] }}</span>
        @endif
        @if(!empty($c['updated_date']))
            <span class="legal-meta-item"><strong>Last updated:</strong> {{ $c['updated_date'] }}</span>
        @endif
    </p>
    @endif

    @if(!empty($c['intro_paragraph']))
    <p class="legal-intro">{!! nl2br(e($c['intro_paragraph'])) !!}</p>
    @endif

    @if($showToc && count($sections) > 1)
    <nav class="legal-toc">
        <div class="legal-toc-label">Contents</div>
        <ol>
            @foreach($sections as $sec)
                @if(!empty($sec['heading']))
                <li><a href="#{{ $slugify($sec['heading']) }}">{{ $sec['heading'] }}</a></li>
                @endif
            @endforeach
        </ol>
    </nav>
    @endif

    @foreach($sections as $sec)
    <section class="legal-section" id="{{ $slugify($sec['heading'] ?? '') }}">
        @if(!empty($sec['heading']))
        <h2 class="legal-section-h2">{{ $sec['heading'] }}</h2>
        @endif
        @foreach($sec['blocks'] ?? [] as $block)
            @if(($block['type'] ?? '') === 'paragraph')
                <p>{!! nl2br(e($block['text'] ?? '')) !!}</p>
            @elseif(($block['type'] ?? '') === 'subheading')
                <h3 class="legal-section-h3">{{ $block['text'] ?? '' }}</h3>
            @elseif(($block['type'] ?? '') === 'list')
                <ul>
                    @foreach($block['items'] ?? [] as $item)
                    <li>{!! nl2br(e($item)) !!}</li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    </section>
    @endforeach
</div>
BLADE
echo "    CREATED resources/views/marketing/sections/legal_doc.blade.php"
fi

# ─── 3. Seed the five legal pages ──────────────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("database/seeders/PlatformMarketingSeeder.php")
s = p.read_text()

anchor = "        $this->command->info('Seeded 9 marketing pages: home, features, pricing, roadmap, changelog, why-intake, contact, invest, __for-industry.');"

new_pages = """        // ═══════════════════════════════════════════════════════════════════════
        // LEGAL PAGES — Terms, Privacy, Cookies, AUP, Sub-processors
        // Entity: "Intake" (WA LLC) · Address: 2935 W Dean, Spokane, WA 99201
        // Venue: Spokane County, Washington · Effective: May 12, 2026
        // ═══════════════════════════════════════════════════════════════════════
        $effectiveDate = 'May 12, 2026';

        // ─── TERMS OF SERVICE ─────────────────────────────────────────────────
        $this->seedPage($platform, 'terms', 'Terms of Service', 'Terms of Service — Intake', [
            ['type' => 'legal_doc', 'content' => [
                'doc_title'        => 'Terms of Service',
                'effective_date'   => $effectiveDate,
                'updated_date'     => '',
                'intro_paragraph'  => 'These Terms of Service ("Terms") govern your access to and use of the Intake platform and services. By creating an account, accessing, or using Intake, you agree to be bound by these Terms. If you do not agree, do not use the service.',
                'show_toc'         => true,
                'sections' => [
                    [
                        'heading' => 'About these terms',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Intake (operated by Intake, a Washington limited liability company located at 2935 W Dean, Spokane, WA 99201) provides a software-as-a-service platform for service businesses to manage appointments, work orders, point-of-sale transactions, and customer relationships ("Service"). When these Terms say "Intake," "we," "us," or "our," we mean the company. When they say "you" or "your," we mean the individual or entity using the Service.'],
                            ['type'=>'paragraph','text'=>'These Terms form a binding agreement between you and Intake. If you are using the Service on behalf of an organization, you represent that you have authority to bind that organization to these Terms, and "you" includes that organization.'],
                        ],
                    ],
                    [
                        'heading' => 'Eligibility and accounts',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'You must be at least 18 years old and able to form a binding contract to use Intake. The Service is intended for businesses, not individual consumers, and is not directed at children.'],
                            ['type'=>'paragraph','text'=>'You are responsible for everything that happens under your account, including all content posted, all bookings created, and all charges incurred. Keep your password secure. Notify us promptly at legal@intake.works if you suspect unauthorized access.'],
                            ['type'=>'paragraph','text'=>'You may invite team members or staff to access your account under team-member permissions. You remain responsible for their actions on the Service.'],
                        ],
                    ],
                    [
                        'heading' => 'Free trial and subscriptions',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Intake offers a 14-day free trial on a selected plan. No credit card is required to start. At the end of the trial, you may subscribe to continue using the Service or your account will pause automatically. Paused accounts retain all data for 90 days before deletion.'],
                            ['type'=>'paragraph','text'=>'Subscriptions renew automatically at the end of each billing period (monthly or annual, as selected) at the then-current rate for your plan. You may cancel at any time from your account settings. Cancellation takes effect at the end of the current billing period — no partial refunds for unused time.'],
                            ['type'=>'paragraph','text'=>'We may change plan pricing with at least 30 days advance notice via email. If you do not agree to a price change, you may cancel before it takes effect.'],
                        ],
                    ],
                    [
                        'heading' => 'Payments and refunds',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Subscription fees are billed in advance and are non-refundable except as required by law or expressly stated in these Terms. We offer a 14-day money-back guarantee on your first paid subscription period — request a refund within 14 days of your first charge via legal@intake.works and we will process it.'],
                            ['type'=>'paragraph','text'=>'Payments are processed by Stripe, Inc. ("Stripe"). When you submit payment information, you are agreeing to Stripe terms of service in addition to these Terms. Intake does not store full payment card numbers on our servers.'],
                            ['type'=>'paragraph','text'=>'Intake itself charges no transaction fees on bookings or sales processed through the Service. Standard payment processor fees (typically 2.9% + 30 cents per transaction in the United States) are charged by Stripe or PayPal and are not within Intake control.'],
                        ],
                    ],
                    [
                        'heading' => 'Your content and data',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'You retain all ownership rights to the content you submit to Intake — your service catalog, customer records, appointment data, branding, and any other content you upload ("Your Content"). We do not claim ownership of Your Content.'],
                            ['type'=>'paragraph','text'=>'You grant Intake a worldwide, non-exclusive, royalty-free license to use, copy, store, transmit, modify, and display Your Content solely to operate and improve the Service, provide support, and comply with legal obligations. This license ends when you delete the content or close your account, except where retention is required by law or for legitimate audit purposes.'],
                            ['type'=>'paragraph','text'=>'You represent that you have all rights necessary to submit Your Content, that it does not infringe any third-party rights, and that its collection and use complies with applicable law (including obtaining any necessary consents from your own customers).'],
                            ['type'=>'paragraph','text'=>'You may export Your Content at any time via the Service export tools. Upon account closure, we retain Your Content for 90 days for reactivation and then permanently delete it unless a longer retention period is required by law.'],
                        ],
                    ],
                    [
                        'heading' => 'Acceptable use',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Your use of the Service is governed by our Acceptable Use Policy, available at /acceptable-use, which is incorporated into these Terms by reference. The Acceptable Use Policy includes restrictions on the types of businesses and activities permitted on Intake.'],
                            ['type'=>'paragraph','text'=>'You may not: reverse-engineer, decompile, or attempt to extract source code; resell, sublicense, or commercially exploit the Service except as expressly permitted; use the Service to send spam, phishing, or unsolicited communications; interfere with the Service operation, security, or other users access; or use the Service in violation of law.'],
                            ['type'=>'paragraph','text'=>'We may suspend or terminate your account for material violations of these Terms or the Acceptable Use Policy. Where practical we will provide notice and an opportunity to cure; for severe violations (illegal activity, payment fraud, harm to other users) suspension may be immediate.'],
                        ],
                    ],
                    [
                        'heading' => 'Intellectual property',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'The Service, including all software, design, text, graphics, logos, and the "Intake" name and marks, is owned by Intake or its licensors and is protected by copyright, trademark, and other intellectual property laws.'],
                            ['type'=>'paragraph','text'=>'We grant you a limited, non-exclusive, non-transferable, revocable license to access and use the Service for your own business purposes, subject to these Terms. This license does not include any right to copy, modify, or distribute the Service code or design.'],
                        ],
                    ],
                    [
                        'heading' => 'Service availability and changes',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We strive for high availability but the Service is provided "as is" and we do not guarantee uninterrupted operation. We may modify, suspend, or discontinue any feature of the Service at any time. For material changes that adversely affect your use, we will provide at least 30 days advance notice when reasonably possible.'],
                            ['type'=>'paragraph','text'=>'Scheduled maintenance is typically performed during low-traffic hours and announced in advance via in-app notification or email.'],
                        ],
                    ],
                    [
                        'heading' => 'Disclaimers',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'TO THE FULLEST EXTENT PERMITTED BY LAW, THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE," WITHOUT WARRANTIES OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING WITHOUT LIMITATION WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, NON-INFRINGEMENT, OR ACCURACY OF DATA.'],
                            ['type'=>'paragraph','text'=>'Intake does not warrant that the Service will meet all your requirements, be uninterrupted or error-free, or that defects will be corrected. You assume responsibility for selecting the Service to achieve your intended results, and for the results obtained from your use.'],
                        ],
                    ],
                    [
                        'heading' => 'Limitation of liability',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'TO THE FULLEST EXTENT PERMITTED BY LAW, INTAKE AND ITS OFFICERS, DIRECTORS, EMPLOYEES, AND AGENTS SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING WITHOUT LIMITATION LOSS OF PROFITS, DATA, USE, GOODWILL, OR OTHER INTANGIBLE LOSSES, ARISING OUT OF OR RELATED TO YOUR USE OF THE SERVICE.'],
                            ['type'=>'paragraph','text'=>'IN NO EVENT SHALL INTAKE TOTAL LIABILITY TO YOU FOR ALL CLAIMS RELATED TO THE SERVICE EXCEED THE GREATER OF: (A) THE AMOUNTS YOU PAID INTAKE IN THE TWELVE MONTHS PRECEDING THE EVENT GIVING RISE TO THE LIABILITY, OR (B) ONE HUNDRED U.S. DOLLARS ($100).'],
                            ['type'=>'paragraph','text'=>'Some jurisdictions do not allow limitations on certain warranties or liabilities, so the above limitations may not apply to you. In such jurisdictions, our liability is limited to the maximum extent permitted by law.'],
                        ],
                    ],
                    [
                        'heading' => 'Indemnification',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'You agree to indemnify, defend, and hold harmless Intake and its officers, directors, employees, and agents from any claims, damages, losses, liabilities, and expenses (including reasonable attorneys fees) arising out of: (a) your use of the Service in violation of these Terms or applicable law; (b) Your Content, including any claim that Your Content infringes a third party rights; or (c) your violation of the Acceptable Use Policy.'],
                        ],
                    ],
                    [
                        'heading' => 'Governing law and venue',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'These Terms are governed by the laws of the State of Washington, without regard to its conflict-of-law principles. Subject to the arbitration provisions below, any dispute that proceeds to court shall be brought exclusively in the state or federal courts located in Spokane County, Washington, and you consent to the personal jurisdiction of those courts.'],
                        ],
                    ],
                    [
                        'heading' => 'Arbitration and class-action waiver',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'PLEASE READ THIS SECTION CAREFULLY. It affects your legal rights.'],
                            ['type'=>'paragraph','text'=>'You and Intake agree that any dispute, claim, or controversy arising out of or relating to these Terms or the Service shall be resolved by binding individual arbitration, except that either party may seek injunctive or equitable relief in court for intellectual property infringement, and either party may bring a claim in small-claims court if the dispute qualifies.'],
                            ['type'=>'paragraph','text'=>'Arbitration shall be administered by the American Arbitration Association (AAA) under its Commercial Arbitration Rules. The arbitration shall take place in Spokane County, Washington, or by video conference. The arbitrator award shall be final and binding and may be entered as a judgment in any court of competent jurisdiction.'],
                            ['type'=>'paragraph','text'=>'YOU AND INTAKE AGREE THAT EACH PARTY MAY BRING CLAIMS AGAINST THE OTHER ONLY IN AN INDIVIDUAL CAPACITY AND NOT AS A PLAINTIFF OR CLASS MEMBER IN ANY PURPORTED CLASS, COLLECTIVE, OR REPRESENTATIVE ACTION. The arbitrator may not consolidate more than one person claims or preside over any form of representative proceeding.'],
                            ['type'=>'paragraph','text'=>'You have the right to opt out of this arbitration provision within 30 days of first agreeing to these Terms by sending written notice to legal@intake.works with the subject line "Arbitration Opt-Out." Opting out does not affect any other terms.'],
                        ],
                    ],
                    [
                        'heading' => 'Termination',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'You may terminate your account at any time by canceling your subscription and following the deletion process in your account settings.'],
                            ['type'=>'paragraph','text'=>'We may suspend or terminate your access to the Service immediately, without notice, if we reasonably believe you have violated these Terms, the Acceptable Use Policy, or applicable law, or if continued provision of the Service would expose Intake to legal liability or material risk.'],
                            ['type'=>'paragraph','text'=>'Upon termination, your right to use the Service ends. Provisions that by their nature should survive termination — including ownership, disclaimers, limitation of liability, indemnification, and dispute resolution — will survive.'],
                        ],
                    ],
                    [
                        'heading' => 'Changes to these terms',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We may update these Terms from time to time. For material changes, we will provide at least 30 days advance notice via email to the address associated with your account or via in-app notification. For non-material changes (typos, clarifications, formatting), we may update the posted version without advance notice. Continued use of the Service after changes take effect constitutes acceptance.'],
                        ],
                    ],
                    [
                        'heading' => 'Miscellaneous',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'These Terms, together with the Privacy Policy, Cookie Policy, and Acceptable Use Policy, constitute the entire agreement between you and Intake regarding the Service and supersede any prior agreements.'],
                            ['type'=>'paragraph','text'=>'If any provision of these Terms is held to be unenforceable, the remaining provisions will remain in effect, and the unenforceable provision will be modified to the minimum extent necessary to make it enforceable.'],
                            ['type'=>'paragraph','text'=>'No waiver of any term shall be deemed a further or continuing waiver. You may not assign these Terms without our prior written consent. We may assign these Terms to any successor by operation of law or in connection with a merger, acquisition, or sale of assets.'],
                            ['type'=>'paragraph','text'=>'Notices to Intake must be sent to legal@intake.works or by mail to Intake, 2935 W Dean, Spokane, WA 99201. Notices to you may be sent to the email address on your account or posted in the Service.'],
                        ],
                    ],
                    [
                        'heading' => 'Contact',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Questions about these Terms? Contact us at legal@intake.works or write to Intake, 2935 W Dean, Spokane, WA 99201.'],
                        ],
                    ],
                ],
            ]],
        ]);

        // ─── PRIVACY POLICY ───────────────────────────────────────────────────
        $this->seedPage($platform, 'privacy', 'Privacy Policy', 'Privacy Policy — Intake', [
            ['type' => 'legal_doc', 'content' => [
                'doc_title'        => 'Privacy Policy',
                'effective_date'   => $effectiveDate,
                'updated_date'     => '',
                'intro_paragraph'  => 'This Privacy Policy describes how Intake collects, uses, and shares information when you use our platform. Intake (operated by Intake, a Washington limited liability company located at 2935 W Dean, Spokane, WA 99201) is the data controller for information collected through our website and Service.',
                'show_toc'         => true,
                'sections' => [
                    [
                        'heading' => 'Information we collect',
                        'blocks' => [
                            ['type'=>'subheading','text'=>'Information you provide directly'],
                            ['type'=>'list','items'=>[
                                'Account information: name, business name, email address, password (stored hashed), phone number, billing address.',
                                'Business information: your service catalog, pricing, hours, resources, staff, branding, custom domains, and other operational details you enter.',
                                'Customer data: information about your own customers that you enter into Intake, including names, contact details, appointment history, and purchase history. You are responsible for collecting this data lawfully and for providing your customers any notices required by law.',
                                'Payment information: when you subscribe, Stripe collects and processes your payment details on our behalf. We receive transaction confirmations but not full payment card numbers.',
                                'Support communications: when you contact us, we collect the content of your message and any attachments.',
                            ]],
                            ['type'=>'subheading','text'=>'Information collected automatically'],
                            ['type'=>'list','items'=>[
                                'Log data: IP address, browser type and version, operating system, referring page, pages visited, time and date of requests, and similar diagnostic information.',
                                'Usage data: features accessed, actions taken, and aggregate performance metrics.',
                                'Cookies: see the Cookie Policy at /cookies for details.',
                            ]],
                            ['type'=>'subheading','text'=>'Information from third parties'],
                            ['type'=>'paragraph','text'=>'When you connect a third-party service to Intake (such as Stripe for payments), we receive certain information from that service to enable the integration, such as confirmation of payment status. The information we receive is governed by both this Privacy Policy and the third party privacy policy.'],
                        ],
                    ],
                    [
                        'heading' => 'How we use information',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We use the information we collect to:'],
                            ['type'=>'list','items'=>[
                                'Provide, operate, and improve the Service.',
                                'Process subscription payments and send billing-related communications.',
                                'Respond to your support requests and communicate with you about your account.',
                                'Send transactional emails (booking confirmations, password resets, security alerts).',
                                'Send product updates and marketing communications, which you can unsubscribe from at any time.',
                                'Detect, prevent, and address fraud, abuse, security incidents, and violations of our Terms.',
                                'Comply with legal obligations and enforce our agreements.',
                                'Analyze aggregate usage patterns to improve performance and develop new features.',
                            ]],
                        ],
                    ],
                    [
                        'heading' => 'How we share information',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We do not sell your personal information. We share information only as described below.'],
                            ['type'=>'subheading','text'=>'Service providers (sub-processors)'],
                            ['type'=>'paragraph','text'=>'We use trusted third parties to operate the Service. These sub-processors are bound by contracts requiring them to protect your information and use it only for the purposes we specify. A current list is maintained at /sub-processors.'],
                            ['type'=>'subheading','text'=>'Legal requirements'],
                            ['type'=>'paragraph','text'=>'We may disclose information if required by law, court order, or government request, or if we reasonably believe disclosure is necessary to protect our rights, your safety, or the safety of others, or to investigate fraud.'],
                            ['type'=>'subheading','text'=>'Business transfers'],
                            ['type'=>'paragraph','text'=>'If Intake is involved in a merger, acquisition, or sale of assets, your information may be transferred as part of that transaction. We will notify you (via email and a prominent notice on our website) of any change in ownership or use of your information.'],
                            ['type'=>'subheading','text'=>'With your consent'],
                            ['type'=>'paragraph','text'=>'We share information with third parties when you direct us to do so, such as when you connect a third-party integration.'],
                        ],
                    ],
                    [
                        'heading' => 'Your customers data',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'When you use Intake to manage your own business, you may enter information about your customers ("Customer Data"). With respect to Customer Data:'],
                            ['type'=>'list','items'=>[
                                'You are the data controller for Customer Data. Intake processes Customer Data on your behalf as a data processor.',
                                'You are responsible for obtaining any consents and providing any notices required by law before entering Customer Data into Intake.',
                                'You are responsible for responding to your customers data subject rights requests (access, deletion, etc.). We will assist you with reasonable requests.',
                                'We do not use Customer Data for our own marketing or to train algorithms outside of providing the Service.',
                            ]],
                        ],
                    ],
                    [
                        'heading' => 'Data retention',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We retain your information for as long as your account is active and as needed to provide the Service. After account closure, we retain your data for 90 days for potential reactivation, then permanently delete it unless a longer retention period is required by law or for legitimate business purposes (such as fraud prevention, dispute resolution, or financial record-keeping).'],
                            ['type'=>'paragraph','text'=>'You may request earlier deletion by emailing privacy@intake.works. We will honor verified deletion requests within 30 days.'],
                        ],
                    ],
                    [
                        'heading' => 'Security',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We implement administrative, technical, and physical safeguards to protect information, including encryption in transit (TLS), encryption at rest for sensitive data, access controls, regular security reviews, and offsite backups. No method of transmission or storage is 100% secure, and we cannot guarantee absolute security.'],
                            ['type'=>'paragraph','text'=>'If we become aware of a security breach that affects your information, we will notify you and applicable authorities as required by law.'],
                        ],
                    ],
                    [
                        'heading' => 'Your rights',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Depending on your location, you may have the following rights regarding your personal information:'],
                            ['type'=>'list','items'=>[
                                'Access — request a copy of the personal information we hold about you.',
                                'Correction — request that we correct inaccurate or incomplete information.',
                                'Deletion — request that we delete your personal information, subject to legal retention requirements.',
                                'Portability — request a copy of your personal information in a structured, machine-readable format.',
                                'Opt-out of marketing — unsubscribe from marketing emails using the link in any marketing message or by emailing privacy@intake.works.',
                                'Restriction — request that we limit how we process your information.',
                                'Objection — object to certain types of processing.',
                            ]],
                            ['type'=>'paragraph','text'=>'To exercise these rights, email privacy@intake.works. We will respond within 30 days. We may need to verify your identity before processing your request. We will not discriminate against you for exercising your rights.'],
                            ['type'=>'paragraph','text'=>'California residents: under the California Consumer Privacy Act (CCPA), you have additional rights including the right to know what categories of personal information we collect and the right to opt out of any "sale" of personal information. We do not sell personal information.'],
                        ],
                    ],
                    [
                        'heading' => 'Children',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Intake is a business tool intended for use by adults. We do not knowingly collect personal information from anyone under the age of 13. If we learn that we have collected personal information from a child under 13, we will delete it. If you believe a child has provided us with personal information, contact privacy@intake.works.'],
                        ],
                    ],
                    [
                        'heading' => 'International transfers',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Intake currently stores and processes data only in the United States. If you access the Service from outside the United States, you understand that your information will be transferred to, stored in, and processed in the United States, which may have different data protection laws than your country.'],
                        ],
                    ],
                    [
                        'heading' => 'Changes to this policy',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We may update this Privacy Policy from time to time. For material changes, we will provide notice via email or in-app notification at least 30 days before the change takes effect. The "Effective" date at the top of this page indicates when the current version became effective. Continued use of the Service after changes take effect constitutes acceptance.'],
                        ],
                    ],
                    [
                        'heading' => 'Contact',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Questions about this Privacy Policy or how we handle your information? Contact us at privacy@intake.works or write to Intake, 2935 W Dean, Spokane, WA 99201.'],
                        ],
                    ],
                ],
            ]],
        ]);

        // ─── COOKIE POLICY ────────────────────────────────────────────────────
        $this->seedPage($platform, 'cookies', 'Cookie Policy', 'Cookie Policy — Intake', [
            ['type' => 'legal_doc', 'content' => [
                'doc_title'        => 'Cookie Policy',
                'effective_date'   => $effectiveDate,
                'updated_date'     => '',
                'intro_paragraph'  => 'This Cookie Policy explains how Intake uses cookies and similar technologies. It is a companion to our Privacy Policy.',
                'show_toc'         => true,
                'sections' => [
                    [
                        'heading' => 'What cookies are',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Cookies are small text files stored on your device when you visit a website. They allow the site to remember information about your visit, such as your login state or preferences, and help with security, analytics, and functionality.'],
                            ['type'=>'paragraph','text'=>'We also use similar technologies such as localStorage and sessionStorage, which work like cookies but are stored differently in your browser. For simplicity, this policy refers to all of these as "cookies."'],
                        ],
                    ],
                    [
                        'heading' => 'Cookies we use',
                        'blocks' => [
                            ['type'=>'subheading','text'=>'Strictly necessary cookies'],
                            ['type'=>'paragraph','text'=>'These cookies are essential for the Service to function. They cannot be turned off without breaking core functionality. We use them for:'],
                            ['type'=>'list','items'=>[
                                'Authentication — keeping you logged into your account (Laravel session cookie).',
                                'Security — protecting against cross-site request forgery (CSRF token).',
                                'Load balancing — routing requests to the right server.',
                            ]],
                            ['type'=>'subheading','text'=>'Analytics cookies'],
                            ['type'=>'paragraph','text'=>'On our marketing website (intake.works), we use Plausible Analytics to understand how visitors discover and use our site. Plausible is a privacy-focused analytics service that does not use cookies and does not collect personal data. It records aggregate information such as page views, referrer URLs, and rough geographic location (country level) without tracking individual visitors across sessions or devices.'],
                            ['type'=>'paragraph','text'=>'Because Plausible does not use cookies and does not collect personal data, we do not require consent for it under applicable cookie laws.'],
                            ['type'=>'subheading','text'=>'Functional cookies'],
                            ['type'=>'paragraph','text'=>'We use a small number of cookies to remember preferences, such as dark mode setting or whether you have dismissed a notification banner. These are set only after you take an action that triggers them.'],
                        ],
                    ],
                    [
                        'heading' => 'Cookies we do not use',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We do not use:'],
                            ['type'=>'list','items'=>[
                                'Advertising or marketing cookies.',
                                'Cross-site tracking pixels (Facebook Pixel, Google Ads remarketing, etc.).',
                                'Third-party data brokers or audience-segmentation services.',
                            ]],
                            ['type'=>'paragraph','text'=>'If this changes in the future — for example, if we add a remarketing pixel for an advertising campaign — we will update this policy and obtain consent before setting non-essential cookies on your device.'],
                        ],
                    ],
                    [
                        'heading' => 'Managing cookies',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'You can control cookies through your browser settings. Most browsers allow you to view, delete, or block cookies. Note that blocking strictly necessary cookies will prevent the Service from working — you will not be able to log in.'],
                            ['type'=>'paragraph','text'=>'Browser cookie controls:'],
                            ['type'=>'list','items'=>[
                                'Chrome: Settings → Privacy and security → Cookies and other site data',
                                'Firefox: Settings → Privacy & Security → Cookies and Site Data',
                                'Safari: Settings → Privacy → Manage Website Data',
                                'Edge: Settings → Cookies and site permissions → Cookies and site data',
                            ]],
                        ],
                    ],
                    [
                        'heading' => 'Changes to this policy',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We may update this Cookie Policy as our use of cookies changes. We will update the "Effective" date at the top of this page when we do.'],
                        ],
                    ],
                    [
                        'heading' => 'Contact',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Questions about cookies? Contact us at privacy@intake.works.'],
                        ],
                    ],
                ],
            ]],
        ]);

        // ─── ACCEPTABLE USE POLICY ────────────────────────────────────────────
        $this->seedPage($platform, 'acceptable-use', 'Acceptable Use Policy', 'Acceptable Use Policy — Intake', [
            ['type' => 'legal_doc', 'content' => [
                'doc_title'        => 'Acceptable Use Policy',
                'effective_date'   => $effectiveDate,
                'updated_date'     => '',
                'intro_paragraph'  => 'This Acceptable Use Policy ("AUP") describes prohibited uses of Intake. It is part of our Terms of Service. By using Intake, you agree to comply with this policy.',
                'show_toc'         => true,
                'sections' => [
                    [
                        'heading' => 'Prohibited businesses',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'You may not use Intake to conduct, sell, or process payments for the following businesses or activities. This list is informed by the restrictions of our payment processors (including Stripe) and reflects categories that present elevated legal, regulatory, or reputational risk.'],
                            ['type'=>'subheading','text'=>'Illegal activities'],
                            ['type'=>'list','items'=>[
                                'Any business or activity that violates federal, state, local, or international law.',
                                'Sale of stolen goods, counterfeit items, or unauthorized copyrighted material.',
                                'Money laundering, terrorism financing, or sanctions violations.',
                            ]],
                            ['type'=>'subheading','text'=>'Regulated and restricted categories'],
                            ['type'=>'list','items'=>[
                                'Cannabis or controlled substances (including in jurisdictions where some forms are legal — federal restrictions remain).',
                                'Firearms, ammunition, gunpowder, explosives, knives intended as weapons, and related accessories.',
                                'Tobacco, e-cigarettes, vaping products, and related accessories.',
                                'Prescription drugs, pharmaceuticals, and any health products requiring a license to sell.',
                                'Gambling, lotteries, sports betting, fantasy sports with cash prizes, and games of chance with monetary value.',
                                'Adult content, escort services, dating services with sexual content, or any sexually explicit material.',
                                'Multi-level marketing, pyramid schemes, and "get rich quick" programs.',
                                'Cryptocurrency exchanges, mining services, or initial coin offerings.',
                                'Payday lending, high-interest consumer loans, debt collection, and credit repair services.',
                                'Bail bonds and other criminal justice-related financial services.',
                            ]],
                            ['type'=>'subheading','text'=>'Misleading or fraudulent business practices'],
                            ['type'=>'list','items'=>[
                                'False, deceptive, or misleading marketing claims.',
                                'Selling services or products you cannot or do not intend to deliver.',
                                'Charging customers for goods or services without consent.',
                                'Operating businesses with chargeback rates exceeding 1% of transactions or refund rates exceeding 5% of transactions.',
                            ]],
                            ['type'=>'subheading','text'=>'Other prohibited businesses'],
                            ['type'=>'list','items'=>[
                                'Travel reservation services that do not deliver the booked service.',
                                'Timeshare resales.',
                                'Telemarketing operations.',
                                'Door-to-door sales operations not registered with applicable consumer protection agencies.',
                                'Any business that promotes hate, violence, or harassment based on race, religion, gender, sexual orientation, national origin, disability, or other protected characteristics.',
                            ]],
                        ],
                    ],
                    [
                        'heading' => 'Prohibited activities',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Regardless of your business type, you may not use Intake to:'],
                            ['type'=>'list','items'=>[
                                'Send unsolicited messages or spam through any communication features.',
                                'Phish, scam, or impersonate any person or entity.',
                                'Upload, transmit, or distribute malware, viruses, or any code designed to disrupt or damage software or systems.',
                                'Attempt to gain unauthorized access to any account, system, or data.',
                                'Reverse-engineer, decompile, or attempt to extract source code from the Service.',
                                'Use automated systems (bots, scrapers, crawlers) to access the Service in a manner that sends more requests than a human can reasonably produce.',
                                'Resell or rebrand the Service to third parties without express written permission.',
                                'Use the Service in any way that could disable, overburden, or impair the Service or interfere with other users.',
                                'Circumvent rate limits, security controls, or access restrictions.',
                                'Use the Service to harass, threaten, defame, or harm any person.',
                                'Collect personal information from your customers without lawful basis or required consent.',
                            ]],
                        ],
                    ],
                    [
                        'heading' => 'Content restrictions',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'You may not post, upload, or transmit content that is:'],
                            ['type'=>'list','items'=>[
                                'Illegal, defamatory, libelous, or violates the rights of others.',
                                'Pornographic, sexually explicit, or contains nudity.',
                                'Promotes hate, violence, terrorism, self-harm, or illegal activity.',
                                'Infringes copyright, trademark, trade secret, or other intellectual property rights.',
                                'Contains another person confidential or private information without their consent.',
                                'Misrepresents your identity, affiliation, or qualifications.',
                            ]],
                        ],
                    ],
                    [
                        'heading' => 'Enforcement',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We reserve the right to investigate and take action against any violation of this AUP. Actions may include:'],
                            ['type'=>'list','items'=>[
                                'Removing or blocking access to violating content.',
                                'Suspending or terminating your account.',
                                'Reporting illegal activity to law enforcement.',
                                'Pursuing legal remedies.',
                            ]],
                            ['type'=>'paragraph','text'=>'Where practical we will provide notice and an opportunity to remedy the violation. For severe violations (illegal activity, fraud, immediate harm to others), action may be immediate and without notice.'],
                            ['type'=>'paragraph','text'=>'If you believe your account has been suspended in error, contact legal@intake.works with the subject "AUP Appeal."'],
                        ],
                    ],
                    [
                        'heading' => 'Reporting violations',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'If you believe someone is violating this AUP, please report it to legal@intake.works with as much detail as possible: the account or content in question, the suspected violation, and any supporting evidence. We will investigate and take appropriate action.'],
                        ],
                    ],
                    [
                        'heading' => 'Changes to this policy',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We may update this AUP from time to time, particularly as our payment processors update their own acceptable-use requirements. For material changes, we will provide notice via email or in-app notification. Continued use of the Service after changes take effect constitutes acceptance.'],
                        ],
                    ],
                    [
                        'heading' => 'Contact',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Questions about this Acceptable Use Policy? Contact legal@intake.works.'],
                        ],
                    ],
                ],
            ]],
        ]);

        // ─── SUB-PROCESSORS ───────────────────────────────────────────────────
        $this->seedPage($platform, 'sub-processors', 'Sub-processors', 'Sub-processors — Intake', [
            ['type' => 'legal_doc', 'content' => [
                'doc_title'        => 'Sub-processors',
                'effective_date'   => $effectiveDate,
                'updated_date'     => '',
                'intro_paragraph'  => 'Intake uses a small number of trusted third-party services to operate the platform. Each is bound by a contract requiring them to protect data and use it only for the purposes we specify. This page lists our current sub-processors. We will update it before adding new ones.',
                'show_toc'         => true,
                'sections' => [
                    [
                        'heading' => 'Current sub-processors',
                        'blocks' => [
                            ['type'=>'subheading','text'=>'DigitalOcean, LLC'],
                            ['type'=>'list','items'=>[
                                'Purpose: Cloud infrastructure — application servers, databases, file storage (Spaces), backups.',
                                'Data: All Service data is stored on DigitalOcean infrastructure in the United States.',
                                'Location: United States.',
                                'Privacy policy: digitalocean.com/legal/privacy-policy',
                            ]],
                            ['type'=>'subheading','text'=>'Stripe, Inc.'],
                            ['type'=>'list','items'=>[
                                'Purpose: Payment processing for Intake subscription fees and (when enabled) for tenant payment processing through Stripe Connect.',
                                'Data: Billing information, transaction records, customer payment details (collected directly by Stripe — Intake does not store full card numbers).',
                                'Location: United States and Stripe global infrastructure.',
                                'Privacy policy: stripe.com/privacy',
                            ]],
                            ['type'=>'subheading','text'=>'PayPal Holdings, Inc.'],
                            ['type'=>'list','items'=>[
                                'Purpose: Alternative payment processing (when enabled by tenants for their customers).',
                                'Data: Transaction records and payment details for transactions processed via PayPal.',
                                'Location: United States and PayPal global infrastructure.',
                                'Privacy policy: paypal.com/us/legalhub/privacy-full',
                            ]],
                            ['type'=>'subheading','text'=>'Resend, Inc.'],
                            ['type'=>'list','items'=>[
                                'Purpose: Transactional email delivery — account confirmations, password resets, booking notifications, security alerts.',
                                'Data: Email addresses, email content, delivery and engagement metadata.',
                                'Location: United States.',
                                'Privacy policy: resend.com/legal/privacy-policy',
                            ]],
                            ['type'=>'subheading','text'=>'Plausible Insights OÜ'],
                            ['type'=>'list','items'=>[
                                'Purpose: Privacy-focused website analytics for the marketing site (intake.works only).',
                                'Data: Anonymous, aggregate page-view data. No cookies. No personal information.',
                                'Location: European Union.',
                                'Privacy policy: plausible.io/privacy',
                            ]],
                        ],
                    ],
                    [
                        'heading' => 'Updates',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'We will update this page at least 30 days before adding a new sub-processor that processes customer data. If you would like to be notified directly of changes, email privacy@intake.works to be added to our sub-processor update list.'],
                            ['type'=>'paragraph','text'=>'Customers with a Data Processing Agreement (DPA) in place may object to a new sub-processor within 30 days of notice. If we cannot accommodate the objection, the customer may terminate the affected services.'],
                        ],
                    ],
                    [
                        'heading' => 'Contact',
                        'blocks' => [
                            ['type'=>'paragraph','text'=>'Questions about our sub-processors or want a copy of our DPA? Contact privacy@intake.works.'],
                        ],
                    ],
                ],
            ]],
        ]);

        $this->command->info('Seeded 14 marketing pages: home, features, pricing, roadmap, changelog, why-intake, contact, invest, __for-industry, terms, privacy, cookies, acceptable-use, sub-processors.');"""

if "'terms', 'Terms of Service'" in s and "'sub-processors'" in s:
    print("    SKIP legal pages — already seeded")
elif anchor not in s:
    raise SystemExit("ABORT legal pages: closing message anchor not found")
else:
    s = s.replace(anchor, new_pages, 1)
    p.write_text(s)
    print("    UPDATED — 5 legal pages seeded")
PYEOF

# ─── 4. Wire footer links (commented out by default) ───────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/marketing/sections/_shell_footer.blade.php")
s = p.read_text()

old = """            <div class="mk-footer-legal">
                <a href="#">Privacy</a>
                <a href="#">Terms</a>"""

new = """            <div class="mk-footer-legal">
                {{-- Legal links commented out until LLC is registered and email forwards are live.
                     Uncomment this block (and delete the two <a href="#"> lines below) to activate. --}}
                {{-- <a href="/privacy">Privacy</a> --}}
                {{-- <a href="/terms">Terms</a> --}}
                {{-- <a href="/cookies">Cookies</a> --}}
                {{-- <a href="/acceptable-use">Acceptable use</a> --}}
                <a href="#">Privacy</a>
                <a href="#">Terms</a>"""

if "Legal links commented out" in s:
    print("    SKIP footer — already wired (commented)")
elif old not in s:
    raise SystemExit("ABORT footer: anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — footer legal links prepared (commented out, ready to activate)")
PYEOF

cat <<EONOTE

==> Patch 58 applied locally.

Deploy:
  git add app/Http/Controllers/Tenant/PageBuilderController.php \\
          resources/views/marketing/sections/legal_doc.blade.php \\
          resources/views/marketing/sections/_shell_footer.blade.php \\
          database/seeders/PlatformMarketingSeeder.php \\
          patch-58-legal-pages.sh
  git commit -m "feat: legal pages — ToS, Privacy, Cookies, AUP, Sub-processors (patch 58)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  php artisan db:seed --class=PlatformMarketingSeeder --force
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify (private URLs — not yet linked from footer):
  https://intake.works/terms
  https://intake.works/privacy
  https://intake.works/cookies
  https://intake.works/acceptable-use
  https://intake.works/sub-processors

Activate footer links (do this AFTER LLC + email forwards + lawyer review):
  Edit resources/views/marketing/sections/_shell_footer.blade.php:
    - Delete the two <a href="#"> lines at the bottom of the block
    - Remove the {{-- --}} from the four legal links above them

This is template content — NOT legal advice. Have an attorney in WA
review before accepting real money from customers.
EONOTE
