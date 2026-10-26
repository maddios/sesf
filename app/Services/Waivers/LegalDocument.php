<?php

namespace App\Services\Waivers;

use App\Models\CarShow;

/**
 * The waiver legal text (port of legacy app/lib/WaiverLegalDocument.php). The wording is copied
 * verbatim from the legacy class; only the PHP around it changed. Do not edit the clauses here
 * without legal sign-off: every signed PDF reproduces them.
 *
 * Only values from our own data (event name, dates, health email) are interpolated, each escaped
 * with htmlspecialchars. Signer-entered values never pass through this class; the PDF template
 * (resources/views/waivers/pdf/document.blade.php) escapes them with Blade.
 */
final class LegalDocument
{
    /** Legacy WaiverSignService::formatEventDateRange: "F j, Y - F j, Y", or '' without both dates. */
    public static function formatEventDateRange(CarShow $show): string
    {
        $start = $show->date_start;
        $end = $show->date_end;
        if ($start === null || $end === null) {
            return '';
        }

        return $start->format('F j, Y').' - '.$end->format('F j, Y');
    }

    /**
     * Legacy WaiverSignService::infectiousBannerSuffix: the season and year of the event start
     * (Mar-May SPRING, Jun-Aug SUMMER, Sep-Nov FALL, else WINTER; a January event is "WINTER" of
     * that same year, as before). Without a start date: the current year only.
     */
    public static function infectiousBannerSuffix(CarShow $show): string
    {
        $start = $show->date_start;
        if ($start === null) {
            return now()->format('Y');
        }
        $month = (int) $start->format('n');
        $season = match (true) {
            $month >= 3 && $month <= 5 => 'SPRING',
            $month >= 6 && $month <= 8 => 'SUMMER',
            $month >= 9 && $month <= 11 => 'FALL',
            default => 'WINTER',
        };

        return $season.' '.$start->format('Y');
    }

    /** The waiver as shown on the signing page (legacy htmlBody). */
    public static function htmlBody(CarShow $show, string $healthEmail): string
    {
        return self::htmlBodyPrefixMain($show)
            .self::htmlBodyInfectiousSection($show, $healthEmail)
            .self::blockLiabilityMedical()
            .'</div>';
    }

    /**
     * The PDF's legal part before the signature page (legacy pdfHtml body prefix): main agreement,
     * then the infectious-disease section on a new page. The caller closes the wrapper div after
     * the sign page. The blank paper form omits clause 9 (electronic signature consent).
     */
    public static function pdfLegalPrefix(CarShow $show, string $healthEmail, bool $paperBlankForm): string
    {
        return self::htmlBodyPrefixMain($show, $paperBlankForm)
            .self::htmlBodyInfectiousSection($show, $healthEmail, true);
    }

    private static function htmlBodyPrefixMain(CarShow $show, bool $omitElectronicClause = false): string
    {
        $name = htmlspecialchars((string) $show->name, ENT_QUOTES, 'UTF-8');
        $range = htmlspecialchars(self::formatEventDateRange($show), ENT_QUOTES, 'UTF-8');

        return '<div class="sesf-waiver-legal">'
            .'<p class="sesf-waiver-title"><strong>RELEASE AND WAIVER OF LIABILITY</strong><br/>'
            .'<strong>ASSUMPTION OF RISK AND INDEMNITY AGREEMENT (“AGREEMENT”)</strong></p>'
            .'<p><strong>EVENT NAME - '.$name.' (and all related activities) ("EVENT")</strong><br/>'
            .'<strong>EVENT DATES - '.$range.'</strong></p>'
            .self::blockConsideration()
            .self::blockSections1to9(! $omitElectronicClause)
            .'<div class="sesf-waiver-float-end"></div>';
    }

    private static function htmlBodyInfectiousSection(CarShow $show, string $healthEmail, bool $pdfPageBreakBefore = false): string
    {
        $suffix = htmlspecialchars(self::infectiousBannerSuffix($show), ENT_QUOTES, 'UTF-8');
        $banner = htmlspecialchars(mb_strtoupper((string) $show->name, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $he = htmlspecialchars($healthEmail, ENT_QUOTES, 'UTF-8');

        $infOpen = $pdfPageBreakBefore
            ? '<div class="sesf-waiver-inf-section" style="page-break-before:always">'
            : '<div class="sesf-waiver-inf-section">';

        return $infOpen
            .'<p class="sesf-waiver-inf-banner"><strong>'.$banner.' EVENT INFECTIOUS DISEASE ACKNOWLEDGEMENT AND WAIVER – '.$suffix.'</strong></p>'
            .'<div class="sesf-waiver-inf-body">'.self::blockInfectious($he).'</div>'
            .'</div>';
    }

    private static function blockConsideration(): string
    {
        return '<p>IN CONSIDERATION of the acceptance of my registration for the above EVENT and being permitted to compete, officiate, observe, work for, or participate in any way in the EVENT or being permitted to enter for any purpose any RESTRICTED AREA (defined as any area of the EVENT requiring special authorization, credentials or permission to enter or any area to which admission by the general public is restricted or prohibited, including but not limited to any competition areas), THE UNDERSIGNED, for himself, spouse, family, heirs, assigns, personal representatives and estate hereby agrees to the following:</p>';
    }

    private static function numberedClause(int $n, string $innerHtml): string
    {
        return '<p class="sesf-waiver-num-item"><strong>'.$n.'.</strong> '.$innerHtml.'</p>';
    }

    private static function blockSections1to9(bool $includeClause9 = true): string
    {
        $html = self::numberedClause(1, 'THE UNDERSIGNED HEREBY RELEASES, WAIVES, DISCHARGES AND COVENANTS NOT TO SUE Southeast Sharkfest Inc. a Florida corporation (“SESF”); promoters, sponsors, and/or advertisers of the EVENT; consultants and others who give recommendations, directions, or instructions, or engage in risk evaluation or loss control activities regarding the premises or EVENT(S) and for each of them, their respective directors, officers, agents, volunteers, and employees, past and present (collectively, the “Released Parties”), FROM ANY AND ALL LIABILITY, CLAIMS, DEMANDS, ACTIONS AND CAUSES OF ACTION WHATSOEVER, INCLUDING ATTORNEYS’ FEES AND COURT COSTS, ARISING OUT OF OR IN ANY MANNER RELATED TO THE UNDERSIGNED’S PARTICIPATION IN THE EVENT, INCLUDING FOR ANY AND ALL LOSS OR DAMAGE, AND ANY CLAIM OR DEMANDS THEREFORE ON ACCOUNT OF INJURY TO THE PERSON OR PROPERTY OR RESULTING IN THE DEATH OF THE UNDERSIGNED ARISING OUT OF OR RELATED TO THE EVENT, WHETHER CAUSED BY THE NEGLIGENCE OF THE RELEASED PARTIES OR OTHERWISE.')
            .self::numberedClause(2, 'The undersigned acknowledges and agrees that participation in the EVENT is completely of his/her free choice, and he/she chooses to participate for his/her own personal benefit. He/she is fully aware of any and all risks involved, if any, involved with participation, and expressly, voluntarily and <strong>WILLINGLY ASSUME FULL RESPONSIBILITY FOR ALL RISKS AND/OR DANGERS</strong> associated with such participation in the EVENT, including but limited to, risk of bodily injury (including permanent disability or death) or property damage arising out of my participation, whether caused by the negligence of the Released Parties or howsoever caused, and he/she accept personal responsibility for any and all damages arising from any such injury or property damage and hold the Released parties harmless there from. The UNDERSIGNED also expressly acknowledges that injuries received may be compounded or increased by negligent rescue operations or procedures of the Released Parties.')
            .self::numberedClause(3, 'The undersigned acknowledges, agrees and represents that he/she has or will immediately upon entering any such RESTRICTED AREA, and will continuously thereafter, inspect the RESTRICTED AREAS which he/she enters and he/she further agrees and warrants that, if at any time, he/she is in or about the RESTRICTED AREA and he/she feels anything to be unsafe, he/she will immediately advise the officials of such and will leave the RESTRICTED AREA prior to the Event.')
            .self::numberedClause(4, '<strong>THE UNDERSIGNED HEREBY AGREES TO INDEMNIFY AND SAVE AND HOLD HARMLESS</strong> the Released Parties and each of them <strong>FROM ANY LOSS, LIABILITY, DAMAGE, OR COST</strong>, including attorneys’ fees and court costs, <strong>ARISING OUT OF OR IN MANNER RELATED TO BODILY INJURY, PROPERTY DAMAGE OR ANY DEFENSE THEREOF</strong> they may incur arising out of the undersigned’s presence or participation in the <strong>EVENT</strong> whether caused by the negligence of the Released Parties or otherwise.')
            .self::numberedClause(5, 'The undersigned hereby agrees that this Agreements extends to all acts of negligence by the Released Parties, including negligent rescue operations and is intended to be as broad and inclusive as is permitted by the laws of the State of Florida and that if any portion thereof is held invalid, it is agreed that the balance shall, notwithstanding, continue in full legal force and effect.')
            .self::numberedClause(6, 'The undersigned expressly waives any defense of enforcement of this Agreement arising from any claims of lack of consideration and understand that this Agreement constitutes a legal, valid and binding waiver of my rights and shall be enforceable against the <strong>UNDERSIGNED</strong>, his spouse, family, heirs, assigns, personal representatives and estate for all purposes.')
            .self::numberedClause(7, 'The undersigned acknowledges and agrees that his/her automobile(s) and he/she may be photographed and/or filmed during the <strong>EVENT</strong> and agree to allow <strong>SESF</strong> to use any photograph, video, image or film likeness of he/she and/or his/her automobile(s) for any legal, legitimate purpose, including without limitation, publication, website and/or other promotional purposes, now and in the future, without financial compensation to him/her. You agree to the use of filming that may also involve the use of <strong>UAVs (Unmanned Aerial Vehicles)</strong> which will be operated in a manner as to avoid directly overflying vehicles as much as feasible.')
            .self::numberedClause(8, 'This Agreement shall be governed by the law of the State of Florida without regard to conflicts of law principles and regardless of where the <strong>EVENT</strong> may have occurred.');
        if ($includeClause9) {
            $html .= self::numberedClause(9, 'The undersigned consents to execute this Agreement electronically and agrees that any electronic signature affixed through SESF\'s signing process has the same legal effect as a handwritten signature, to the fullest extent permitted by applicable law, including the Electronic Signatures in Global and National Commerce Act, 15 U.S.C. § 7001 et seq., and Chapter 668, Florida Statutes. The undersigned further agrees that SESF may retain this executed Agreement and related electronic records, including signature data and transaction metadata, in electronic form for so long as reasonably necessary for event administration, insurance and risk management, dispute resolution, regulatory compliance, and enforcement of this Agreement. By proceeding with electronic execution, the undersigned acknowledges having had a reasonable opportunity to review this Agreement and accepts this paragraph.');
        }

        return '<div class="sesf-waiver-num-list">'.$html.'</div>';
    }

    public static function clauseStylesCss(): string
    {
        return '.sesf-waiver-num-list{margin:0.65em 0 0.65em 1.1em;padding:0;}'
            .'.sesf-waiver-num-item{margin:0 0 0.85em 0;padding-left:1.75em;text-indent:-1.75em;line-height:1.45;}'
            .'.sesf-waiver-num-item strong{font-weight:700;}'
            .'.sesf-waiver-inf-section{margin:1.25em 0;}'
            .'.sesf-waiver-inf-banner{text-align:left;margin:0 0 0.5em 0;clear:both;}'
            .'.sesf-waiver-inf-body{margin:0;padding-left:1.35em;}'
            .'.sesf-waiver-inf-body p{margin:0 0 0.85em 0;}'
            .'.sesf-waiver-float-end{clear:both;height:0;margin:0;padding:0;overflow:hidden;}';
    }

    private static function blockInfectious(string $healthEmail): string
    {
        return '<p>There is a public health risk from infectious diseases. Such diseases are known to spread quickly via person-to-person transmission and may spread without any obvious symptoms. This is a collective and unified effort, with everyone sharing in the obligation to do their part to be, and stay, safe. I agree to comply with all applicable United States laws, regulations, and guidance pertaining to infectious diseases.</p>'
            .'<p>Being aware of the above, I acknowledge I am voluntarily participating in this activity/SESF event. I further understand that SESF cannot guarantee a contagious or infectious disease-free environment at and during this activity/SESF event and as with any public gathering, there is an inherent risk of infection.</p>'
            .'<p>I agree that if I am exhibiting symptoms of acute respiratory illness, a fever of 100.4ºF or higher, or signs of a fever, I will remain isolated and self-quarantine until I have been fever-free for 24 hours without the use of medication, or other quarantine requirements imposed by applicable local or national laws. In the event the Federal, State or local government policy or regulations require me to self-quarantine because of symptoms, contact tracing, or I otherwise determine based on my own judgment or that of a medical professional to selfquarantine, I will notify SESF immediately in writing at '
            .'<strong>'.$healthEmail.'</strong> and stop attending all SESF events.</p>'
            .'<p>By signing this Agreement, I acknowledge the contagious nature of infectious diseases, the fact that they can be difficult to identify in another individual, and the inherent risks of exposure during any activity to those who may be infected with contagious or infectious disease(s). I voluntarily assume the risk that I may be exposed to or infected by contagious or infectious disease by participating in an activity/SESF event, and that such exposure or infection may result in personal injury, illness, permanent disability, and/or even death.</p>'
            .'<p>I voluntarily assume full responsibility for any and all risks of illness or injury associated with my exposure to infectious diseases, as well as from use of any protective equipment. I completely absolve the SESF, its directors, trustees, officers, employees, agents, and contractors and any and all legal or financial responsibility, including, but not limited to, any personal injury, disability, illness, damage or death from exposure to infectious diseases. Also, to the fullest extent permissible by applicable law, I agree, on behalf of myself, my personal representatives and heirs, not to make any type of legal or equitable claim on the SESF, or any of its directors, trustees, officers, employees, agents or contractors with respect to any exposure I may have to any infectious diseases, whether or not it arises through the negligence, omission, default or other action of anyone affiliated with SESF, including fellow attendees or contractors. I further agree that if any such claim is made, I will indemnify and defend SESF with respect to any such claims.</p>';
    }

    /** Liability, medical release and acknowledgement (top of the PDF signature page). */
    public static function blockLiabilityMedical(): string
    {
        return '<p><strong>LIABILITY WAIVER AND RELEASE OF CLAIMS:</strong><br/>'
            .'I acknowledge that I derive personal satisfaction and a benefit by virtue of my participation and/or voluntarism with SESF, and I willingly engage in SESF events and/or other activities (the “Activity”).</p>'
            .'<p><strong>MEDICAL ACKNOWLEDGMENT AND RELEASE.</strong><br/>'
            .'I DO HEREBY RELEASE AND FOREVER DISCHARGE THE RELEASED PARTIES FROM ANY CLAIM WHATSOEVER WHICH ARISES OR MAY HEREAFTER ARISE ON ACCOUNT OF ANY FIRST AID, TREATMENT, OR SERVICE RENDERED IN CONNECTION WITH MY PARTICIPATION IN THE ACTIVITY.</p>'
            .'<p><strong>RELEASE AND WAIVER.</strong><br/>'
            .'I HEREBY RELEASE, WAIVE AND FOREVER DISCHARGE ANY AND ALL LIABILITY, CLAIMS, AND DEMANDS OF WHATEVER KIND OR NATURE AGAINST THE SESF AND ITS AFFILIATED PARTNERS AND SPONSORS, INCLUDING IN EACH CASE, WITHOUT LIMITATION, THEIR DIRECTORS, OFFICERS, EMPLOYEES, VOLUNTEERS, AND AGENTS (THE “RELEASED PARTIES”), EITHER IN LAW OR IN EQUITY, TO THE FULLEST EXTENT PERMISSIBLE BY LAW, INCLUDING BUT NOT LIMITED TO DAMAGES OR LOSSES CAUSED BY THE NEGLIGENCE, FAULT OR CONDUCT OF ANY KIND ON THE PART OF THE RELEASED PARTIES, INCLUDING BUT NOT LIMITED TO DEATH, BODILY INJURY, ILLNESS, ECONOMIC LOSS OR OUT OF POCKET EXPENSES, OR LOSS OR DAMAGE TO PROPERTY, WHICH I, MY HEIRS, ASSIGNEES, NEXT OF KIN AND/OR LEGALLY APPOINTED OR DESIGNATED REPRESENTATIVES, MAY HAVE OR WHICH MAY HEREINAFTER ACCRUE ON MY BEHALF, WHICH ARISE OR MAY HEREINAFTER ARISE FROM MY PARTICIPATION WITH THE ACTIVITY.</p>'
            .'<p><strong>I AM OVER THE AGE OF EIGHTEEN AND HAVE SOUND, LEGAL CAPACITY. I HAVE BEEN GIVEN THE TIME AND OPPORTUNITY TO INDEPENDENTLY CONSULT LEGAL COUNSEL REGARDING THE TERMS OF THIS AGREEMENT. I HAVE READ THIS RELEASE AND WAIVER OF LIABILITY, ASSUMPTION OF RISK AND INDEMNITY AGREEMENT AND THE COVID-19 SAFETY INFORMATION AND ADDENDUM, FULLY UNDERSTAND ITS TERMS, UNDERSTAND THAT I HAVE GIVEN UP SUBSTANTIAL RIGHTS, INCLUDING THE RIGHT TO SUE, BY SIGNING IT. I HAVE SIGNED THIS AGREEMENT FREELY AND VOLUNTARILY WITHOUT ANY INDUCEMENT, ASSURANCE OR GUARANTEE BEING MADE TO ME AND INTEND MY SIGNATURE TO BE A COMPLETE AND UNCONDITIONAL RELEASE OF ALL LIABILITY TO THE GREATEST ALLOWED BY LAW.</strong></p>';
    }
}
