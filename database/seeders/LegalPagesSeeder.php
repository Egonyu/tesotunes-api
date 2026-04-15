<?php

namespace Database\Seeders;

use App\Models\LegalPage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LegalPagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Terms of Service
        LegalPage::firstOrCreate(
            ['slug' => 'terms-of-service'],
            [
                'title' => 'Terms of Service',
                'type' => LegalPage::TYPE_TERMS_OF_SERVICE,
                'description' => 'Platform usage terms and conditions',
                'applies_to' => LegalPage::APPLIES_TO_ALL,
                'status' => LegalPage::STATUS_PUBLISHED,
                'requires_acceptance' => true,
                'published_at' => now(),
                'content' => $this->getTermsOfServiceContent(),
            ]
        );

        // Artist Agreement
        LegalPage::firstOrCreate(
            ['slug' => 'artist-agreement'],
            [
                'title' => 'Artist Agreement',
                'subtitle' => 'Distribution, Revenue Sharing & Compliance',
                'type' => LegalPage::TYPE_ARTIST_AGREEMENT,
                'description' => 'Terms specific to artist partners and content creators',
                'applies_to' => LegalPage::APPLIES_TO_ARTISTS,
                'status' => LegalPage::STATUS_PUBLISHED,
                'requires_acceptance' => true,
                'published_at' => now(),
                'content' => $this->getArtistAgreementContent(),
            ]
        );

        // Privacy Policy
        LegalPage::firstOrCreate(
            ['slug' => 'privacy-policy'],
            [
                'title' => 'Privacy Policy',
                'type' => LegalPage::TYPE_PRIVACY_POLICY,
                'description' => 'How we collect, use, and protect your personal data',
                'applies_to' => LegalPage::APPLIES_TO_ALL,
                'status' => LegalPage::STATUS_PUBLISHED,
                'requires_acceptance' => false,
                'published_at' => now(),
                'content' => $this->getPrivacyPolicyContent(),
            ]
        );

        // Acceptable Use Policy
        LegalPage::firstOrCreate(
            ['slug' => 'acceptable-use-policy'],
            [
                'title' => 'Acceptable Use Policy',
                'type' => LegalPage::TYPE_ACCEPTABLE_USE,
                'description' => 'Rules and restrictions for platform usage',
                'applies_to' => LegalPage::APPLIES_TO_ALL,
                'status' => LegalPage::STATUS_PUBLISHED,
                'requires_acceptance' => true,
                'published_at' => now(),
                'content' => $this->getAcceptableUsePolicyContent(),
            ]
        );

        // Payment Terms
        LegalPage::firstOrCreate(
            ['slug' => 'payment-terms'],
            [
                'title' => 'Payment Terms & Conditions',
                'type' => LegalPage::TYPE_PAYMENT_TERMS,
                'description' => 'Payment, billing, and financial transaction policies',
                'applies_to' => LegalPage::APPLIES_TO_ALL,
                'status' => LegalPage::STATUS_PUBLISHED,
                'requires_acceptance' => true,
                'published_at' => now(),
                'content' => $this->getPaymentTermsContent(),
            ]
        );

        // Copyright & DMCA
        LegalPage::firstOrCreate(
            ['slug' => 'copyright-policy'],
            [
                'title' => 'Copyright & DMCA Policy',
                'type' => LegalPage::TYPE_COPYRIGHT,
                'description' => 'Intellectual property rights and takedown procedures',
                'applies_to' => LegalPage::APPLIES_TO_ALL,
                'status' => LegalPage::STATUS_PUBLISHED,
                'requires_acceptance' => false,
                'published_at' => now(),
                'content' => $this->getCopyrightPolicyContent(),
            ]
        );

        // Cookie Policy
        LegalPage::firstOrCreate(
            ['slug' => 'cookie-policy'],
            [
                'title' => 'Cookie Policy',
                'type' => LegalPage::TYPE_COOKIES,
                'description' => 'Information about cookies and tracking technologies',
                'applies_to' => LegalPage::APPLIES_TO_ALL,
                'status' => LegalPage::STATUS_PUBLISHED,
                'requires_acceptance' => false,
                'published_at' => now(),
                'content' => $this->getCookiePolicyContent(),
            ]
        );
    }

    private function getTermsOfServiceContent(): string
    {
        return <<<'HTML'
<h1>Terms of Service</h1>
<p>Last Updated: April 15, 2026</p>

<h2>1. Agreement to Terms</h2>
<p>By accessing and using TesoTunes ("Platform", "Service"), you accept and agree to be bound by the terms and provision of this agreement. If you do not agree to abide by the above, please do not use this service.</p>

<h2>2. Use License</h2>
<p>Permission is granted to temporarily download one copy of the materials (information or software) on TesoTunes for personal, non-commercial transitory viewing only. This is the grant of a license, not a transfer of title, and under this license you may not:</p>
<ul>
    <li>Modify or copy the materials</li>
    <li>Use the materials for any commercial purpose or for any public display</li>
    <li>Attempt to decompile or reverse engineer any software contained on the Platform</li>
    <li>Remove any copyright or other proprietary notations from the materials</li>
    <li>Transfer the materials to another person or "mirror" the materials on any other server</li>
    <li>Violate any applicable laws or regulations</li>
    <li>Access or attempt to access the Platform through automated means without authorization</li>
</ul>

<h2>3. Disclaimer</h2>
<p>The materials on TesoTunes are provided on an 'as is' basis. TesoTunes makes no warranties, expressed or implied, and hereby disclaims and negates all other warranties including, without limitation, implied warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of intellectual property or other violation of rights.</p>

<h2>4. Limitations</h2>
<p>In no event shall TesoTunes or its suppliers be liable for any damages (including, without limitation, damages for loss of data or profit, or due to business interruption) arising out of the use or inability to use the materials on the Platform.</p>

<h2>5. Accuracy of Materials</h2>
<p>The materials appearing on TesoTunes could include technical, typographical, or photographic errors. TesoTunes does not warrant that any of the materials on the Platform are accurate, complete, or current. TesoTunes may make changes to the materials contained on the Platform at any time without notice.</p>

<h2>6. User Responsibilities</h2>
<p>You are responsible for maintaining the confidentiality of your account passwords and for all activities that occur under your account. You agree to notify TesoTunes immediately of any unauthorized use of your account.</p>

<h2>7. Content &amp; Intellectual Property</h2>
<p>All content on the Platform, including music, artwork, text, and software, is protected by copyright laws. You agree not to reproduce, distribute, or transmit any content without proper authorization.</p>

<h2>8. Third-Party Links</h2>
<p>TesoTunes has not reviewed all of the sites linked to the Platform and is not responsible for the contents of any such linked site. The inclusion of any link does not imply endorsement by TesoTunes of the site. Use of any such linked website is at the user's own risk.</p>

<h2>9. Modifications to Terms</h2>
<p>TesoTunes may revise these terms of service at any time without notice. By using the Platform, you are agreeing to be bound by the then current version of these terms of service.</p>

<h2>10. Governing Law</h2>
<p>These terms and conditions are governed by and construed in accordance with the laws of Uganda, and you irrevocably submit to the exclusive jurisdiction of the courts located in Kampala, Uganda.</p>

<h2>11. Contact Information</h2>
<p>If you have questions or concerns about these Terms of Service, please contact us at legal@tesotunes.com</p>
HTML;
    }

    private function getArtistAgreementContent(): string
    {
        return <<<'HTML'
<h1>TesoTunes Artist Agreement</h1>
<p>Last Updated: April 15, 2026</p>

<h2>Between: TesoTunes Limited ("Platform")</h2>
<p>And: The Artist/Content Creator ("You" or "Artist")</p>

<h2>1. Partnership &amp; Rights</h2>
<p>By joining TesoTunes as an artist, you grant the Platform a non-exclusive license to:</p>
<ul>
    <li>Distribute your music across streaming platforms and digital marketplaces</li>
    <li>Display your artwork, metadata, and promotional content</li>
    <li>Collect and remit royalties on your behalf</li>
    <li>Generate analytics and reports on your content performance</li>
</ul>

<h2>2. Revenue Sharing Model</h2>
<p>TesoTunes operates under a transparent revenue sharing model:</p>
<ul>
    <li><strong>Streaming Revenue:</strong> You receive 70% of net streaming revenue; TesoTunes retains 30% for platform operations</li>
    <li><strong>Downloads:</strong> You receive 70% of purchase price; TesoTunes retains 30%</li>
    <li><strong>Fan Tips:</strong> You receive 100% of fan tips with no platform fee</li>
    <li><strong>Collaborations:</strong> Revenue is split according to agreed royalty splits with all featured artists</li>
</ul>

<h2>3. Minimum Withdrawal &amp; Payout</h2>
<p>You must accumulate a minimum of 50,000 UGX before requesting a payout. Payouts are processed on a monthly basis via your verified payment methods (MTN MoMo, Airtel Money, or Bank Transfer).</p>

<h2>4. Artist Verification &amp; KYC</h2>
<p>To receive payments, you must complete Know Your Customer (KYC) verification, including:</p>
<ul>
    <li>Valid government-issued ID</li>
    <li>Proof of address</li>
    <li>Banking information or mobile money account</li>
    <li>Tax identification number (if applicable)</li>
</ul>

<h2>5. Content Rights &amp; Ownership</h2>
<p>You warrant that you own or have licensed all rights to the music and content you upload. You are responsible for:</p>
<ul>
    <li>Obtaining all necessary permissions from co-artists, producers, and rights holders</li>
    <li>Paying all owed royalties to collaborators</li>
    <li>Ensuring all content complies with copyright and intellectual property laws</li>
</ul>

<h2>6. Prohibited Content</h2>
<p>The following content is strictly prohibited:</p>
<ul>
    <li>Music that infringes on third-party copyrights</li>
    <li>Content that promotes violence, hate, or illegal activities</li>
    <li>Explicit content targeting minors</li>
    <li>Spam, duplicate, or test files</li>
    <li>Content uploaded without proper authorization</li>
</ul>

<h2>7. Metadata &amp; Attribution</h2>
<p>You agree to provide accurate metadata including:</p>
<ul>
    <li>ISRC codes</li>
    <li>Artist names and collaborators</li>
    <li>Genre, mood, and language tags</li>
    <li>Correct release dates</li>
    <li>Artwork and promotional images</li>
</ul>

<h2>8. Royalty Splits &amp; Collaborations</h2>
<p>For collaborative works, you must specify royalty splits with all contributors. TesoTunes will distribute revenue according to these agreements.</p>

<h2>9. Content Removal &amp; Account Suspension</h2>
<p>TesoTunes reserves the right to:</p>
<ul>
    <li>Remove content that violates these terms</li>
    <li>Suspend or terminate your account for policy violations</li>
    <li>Withhold payments pending investigation of copyright claims</li>
</ul>

<h2>10. Tax Responsibilities</h2>
<p>You are responsible for all applicable taxes on your earnings. TesoTunes will provide tax documentation as required by law. For payments exceeding 3 million UGX annually, withholding tax may apply.</p>

<h2>11. Liability &amp; Indemnification</h2>
<p>You indemnify TesoTunes against any claims, damages, or losses arising from:</p>
<ul>
    <li>Inaccurate metadata</li>
    <li>Copyright infringement</li>
    <li>Violation of third-party rights</li>
    <li>Your breach of this agreement</li>
</ul>

<h2>12. Term &amp; Termination</h2>
<p>This agreement continues until terminated by either party. Upon termination:</p>
<ul>
    <li>New uploads will no longer be accepted</li>
    <li>Existing content remains live during wind-down period</li>
    <li>Final payments are processed within 45 days</li>
</ul>

<h2>13. Amendments</h2>
<p>TesoTunes may modify these terms with 30 days' notice. Continued use of the Platform constitutes acceptance of updated terms.</p>

<h2>14. Contact &amp; Disputes</h2>
<p>For disputes or inquiries, contact: artist-support@tesotunes.com</p>
HTML;
    }

    private function getPrivacyPolicyContent(): string
    {
        return <<<'HTML'
<h1>Privacy Policy</h1>
<p>Last Updated: April 15, 2026</p>

<h2>1. Introduction</h2>
<p>TesoTunes Limited is committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you visit our website and use our services.</p>

<h2>2. Information We Collect</h2>

<h3>A. Information You Provide</h3>
<ul>
    <li>Registration data (name, email, phone, date of birth)</li>
    <li>Payment and banking information</li>
    <li>Profile information (bio, photo, social links)</li>
    <li>Communications with us (support tickets, feedback)</li>
</ul>

<h3>B. Automatically Collected Information</h3>
<ul>
    <li>Device information (IP address, browser type, device ID)</li>
    <li>Usage data (songs played, listening history, engagement)</li>
    <li>Location data (if permitted)</li>
    <li>Cookies and similar tracking technologies</li>
</ul>

<h3>C. Third-Party Information</h3>
<p>We may receive information about you from:</p>
<ul>
    <li>Payment processors and banking partners</li>
    <li>Social media platforms (if you connect your account)</li>
    <li>Analytics providers</li>
</ul>

<h2>3. How We Use Your Information</h2>
<p>We use collected information to:</p>
<ul>
    <li>Provide and improve our services</li>
    <li>Process payments and payouts</li>
    <li>Send notifications and updates</li>
    <li>Personalize your experience</li>
    <li>Enforce our terms and policies</li>
    <li>Comply with legal obligations</li>
</ul>

<h2>4. Data Sharing</h2>
<p>We do NOT sell your data. We may share information with:</p>
<ul>
    <li>Payment processors and financial institutions</li>
    <li>Service providers and contractors (data processors)</li>
    <li>Law enforcement (when required by law)</li>
    <li>Distribution partners (with your consent)</li>
</ul>

<h2>5. Data Security</h2>
<p>We implement industry-standard security measures including:</p>
<ul>
    <li>Encryption (SSL/TLS)</li>
    <li>Secure password hashing</li>
    <li>Regular security audits</li>
    <li>Access controls and monitoring</li>
</ul>

<h2>6. Your Rights</h2>
<p>You have the right to:</p>
<ul>
    <li>Access your personal data</li>
    <li>Correct inaccurate information</li>
    <li>Delete your account and data</li>
    <li>Opt-out of marketing communications</li>
    <li>Port your data</li>
</ul>

<h2>7. Retention</h2>
<p>We retain your data for as long as necessary to provide services. After account deletion, we retain transaction records for 7 years for tax and legal compliance.</p>

<h2>8. Children's Privacy</h2>
<p>TesoTunes is not intended for users under 18. We do not knowingly collect information from minors.</p>

<h2>9. Contact Us</h2>
<p>For privacy inquiries: privacy@tesotunes.com</p>
HTML;
    }

    private function getAcceptableUsePolicyContent(): string
    {
        return <<<'HTML'
<h1>Acceptable Use Policy</h1>
<p>Last Updated: April 15, 2026</p>

<h2>1. Prohibited Activities</h2>
<p>You agree not to use TesoTunes for:</p>
<ul>
    <li><strong>Illegal Activities:</strong> Violating any applicable law or regulation</li>
    <li><strong>Copyright Infringement:</strong> Uploading or distributing infringing content</li>
    <li><strong>Harassment &amp; Abuse:</strong> Harassing, bullying, or threatening other users</li>
    <li><strong>Hate Speech:</strong> Content promoting discrimination or violence</li>
    <li><strong>Fraud &amp; Deception:</strong> Impersonation or misleading information</li>
    <li><strong>Spam:</strong> Uploading duplicate, spam, or test files</li>
    <li><strong>Unauthorized Access:</strong> Attempting to hack or gain unauthorized access</li>
    <li><strong>Adult Content:</strong> Explicit content targeting minors</li>
</ul>

<h2>2. Account Responsibilities</h2>
<p>You are responsible for:</p>
<ul>
    <li>Maintaining account security and confidentiality</li>
    <li>All activities under your account</li>
    <li>Notifying us of unauthorized access</li>
</ul>

<h2>3. Content Guidelines</h2>
<p>Artists must ensure uploaded content:</p>
<ul>
    <li>Is original or properly licensed</li>
    <li>Contains accurate metadata</li>
    <li>Includes proper ISRC codes</li>
    <li>Credits all contributors</li>
</ul>

<h2>4. Enforcement</h2>
<p>Violations may result in:</p>
<ul>
    <li>Content removal</li>
    <li>Account suspension</li>
    <li>Loss of payout rights</li>
    <li>Permanent account termination</li>
    <li>Legal action</li>
</ul>

<h2>5. Reporting Violations</h2>
<p>Report violations to: abuse@tesotunes.com</p>
HTML;
    }

    private function getPaymentTermsContent(): string
    {
        return <<<'HTML'
<h1>Payment Terms &amp; Conditions</h1>
<p>Last Updated: April 15, 2026</p>

<h2>1. Payment Processing</h2>
<p>TesoTunes processes payments through:</p>
<ul>
    <li>MTN MoMo (Mobile Money)</li>
    <li>Airtel Money</li>
    <li>Bank Transfers</li>
</ul>

<h2>2. Payout Eligibility</h2>
<p>To request a payout, you must:</p>
<ul>
    <li>Have a minimum balance of 50,000 UGX</li>
    <li>Complete KYC verification</li>
    <li>Accept the current version of our terms</li>
</ul>

<h2>3. Payout Schedule</h2>
<p>Payouts are processed on a monthly basis, typically by the 15th of each month for balances accumulated in the previous month.</p>

<h2>4. Processing Times</h2>
<ul>
    <li>Mobile Money: 1-24 hours</li>
    <li>Bank Transfers: 3-5 business days</li>
    <li>International Transfers: 5-10 business days</li>
</ul>

<h2>5. Transaction Fees</h2>
<p>Payout fees are:</p>
<ul>
    <li>Mobile Money: 1.5% (minimum 500 UGX)</li>
    <li>Bank Transfer: 2% (minimum 1,000 UGX)</li>
</ul>

<h2>6. Currency &amp; Exchange</h2>
<p>All payments are in Ugandan Shillings (UGX). International payments are converted at the current market rate.</p>

<h2>7. Holds &amp; Disputes</h2>
<p>TesoTunes reserves the right to hold payments pending:</p>
<ul>
    <li>Copyright or IP claim investigations</li>
    <li>Payment disputes</li>
    <li>Account verification</li>
</ul>

<h2>8. Refund Policy</h2>
<p>Users may request refunds within 30 days of purchase for:</p>
<ul>
    <li>Accidental purchases</li>
    <li>Service failures</li>
</ul>

<h2>9. Subscription Terms</h2>
<p>Subscriptions renew automatically. You may cancel anytime before the next billing date with no penalty.</p>

<h2>10. Chargebacks</h2>
<p>False chargebacks may result in account suspension and legal action.</p>

<h2>11. Tax Withholding</h2>
<p>TesoTunes complies with Ugandan tax law. Artists earning over 3 million UGX annually are subject to applicable withholding tax (currently 20%).</p>

<h2>12. Contact Support</h2>
<p>For payment issues: payments@tesotunes.com</p>
HTML;
    }

    private function getCopyrightPolicyContent(): string
    {
        return <<<'HTML'
<h1>Copyright &amp; DMCA Policy</h1>
<p>Last Updated: April 15, 2026</p>

<h2>1. Copyright Ownership</h2>
<p>Artists retain full ownership of their works. By uploading content to TesoTunes, you grant us a license to distribute and monetize on your behalf.</p>

<h2>2. Copyright Infringement</h2>
<p>TesoTunes has zero tolerance for copyright infringement. We actively monitor for and remove infringing content.</p>

<h2>3. DMCA Takedown Process</h2>
<p>If you believe your copyright has been infringed:</p>
<ol>
    <li>Send a detailed DMCA notice to: legal@tesotunes.com</li>
    <li>Include the specific infringing content URL</li>
    <li>Provide proof of ownership</li>
    <li>We will investigate and remove within 48 hours if valid</li>
</ol>

<h2>4. Counter-Notices</h2>
<p>If your content was removed and you believe it was a mistake, you may file a counter-notice. False counter-notices may result in legal action.</p>

<h2>5. Repeat Infringers</h2>
<p>Accounts with multiple copyright violations will be permanently terminated and referred to authorities.</p>

<h2>6. Third-Party Rights</h2>
<p>Artists are responsible for obtaining licenses for:</p>
<ul>
    <li>Samples and interpolations</li>
    <li>Featured artists and producers</li>
    <li>Publishing and mechanical rights</li>
</ul>

<h2>7. Royalty Splits</h2>
<p>You must ensure all royalty splits with co-writers, producers, and featured artists are declared and accurate.</p>

<h2>8. Content ID &amp; Monetization</h2>
<p>TesoTunes may use Content ID technology to identify and monetize copyrighted content across our network.</p>
HTML;
    }

    private function getCookiePolicyContent(): string
    {
        return <<<'HTML'
<h1>Cookie Policy</h1>
<p>Last Updated: April 15, 2026</p>

<h2>1. What Are Cookies?</h2>
<p>Cookies are small files stored on your device that help us recognize you and improve your experience.</p>

<h2>2. Types of Cookies We Use</h2>

<h3>Essential Cookies</h3>
<p>Required for login, security, and basic functionality.</p>

<h3>Performance Cookies</h3>
<p>Help us understand how users interact with the Platform.</p>

<h3>Marketing Cookies</h3>
<p>Used for targeted advertising and analytics.</p>

<h2>3. Third-Party Cookies</h2>
<p>We partner with analytics providers (Google Analytics, Mixpanel) who may place cookies.</p>

<h2>4. Cookie Duration</h2>
<ul>
    <li>Session cookies expire when you close your browser</li>
    <li>Persistent cookies last up to 2 years</li>
</ul>

<h2>5. Managing Cookies</h2>
<p>You can control cookies through your browser settings. Disabling cookies may limit functionality.</p>

<h2>6. Do Not Track</h2>
<p>If you enable "Do Not Track" in your browser, we will limit certain tracking technologies.</p>

<h2>7. Mobile Apps</h2>
<p>Our mobile apps use local storage and analytics identifiers similar to cookies.</p>

<h2>8. Changes</h2>
<p>We may update this policy as technologies evolve.</p>
HTML;
    }
}
