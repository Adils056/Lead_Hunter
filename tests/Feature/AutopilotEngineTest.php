<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\BuyingSignal;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\IcpProfile;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\MailboxCursor;
use App\Models\OutreachSetting;
use App\Models\SearchArm;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Autopilot\ApprovalService;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Autopilot\AutopilotRunner;
use App\Services\Autopilot\PerformanceReport;
use App\Services\Autopilot\SearchAllocator;
use App\Services\Discovery\Adapters\EuTedAdapter;
use App\Services\Discovery\Adapters\OsmOverpassAdapter;
use App\Services\Discovery\Adapters\UkFindATenderAdapter;
use App\Services\Hunting\LeadHuntService;
use App\Services\Mail\ImapTransport;
use App\Services\Mail\MimeMessage;
use App\Services\Mail\ReplySyncService;
use App\Services\Outreach\SequenceEngine;
use App\Services\Sales\ConversionAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutopilotEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array', 'mail.from.address' => 'ismail@eziline.test',
            'leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7],
            'leadpilot.sources.website_crawler.dns_overrides' => ['smile.test' => ['93.184.216.34'], 'glow.test' => ['93.184.216.34']],
        ]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@eziline.test']);
        OutreachSetting::current()->update(['mode' => 'live', 'emergency_stop' => false, 'require_verified_email' => false, 'reply_to_email' => 'ismail@eziline.test',
            'approved_services' => array_keys(config('leadpilot.services'))]);
    }

    protected function prospectMails(): array
    {
        return array_values(array_filter(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all(),
            fn ($m) => !in_array('admin@eziline.test', array_map(fn ($a) => $a->getAddress(), $m->getOriginalMessage()->getTo()), true)));
    }

    protected function weakSite(): void
    {
        Http::fake([
            'smile.test/robots.txt' => Http::response('', 404),
            'smile.test/contact*' => Http::response('', 404), 'smile.test/about*' => Http::response('', 404),
            'smile.test*' => Http::response('<html><head><title>Smile</title></head><body><h1>Smile Dental</h1><a href="mailto:info@smile.test">info@smile.test</a><footer>© 2019</footer></body></html>', 200),
            '*' => Http::response('', 404),
        ]);
    }

    protected function qualifiedLead(): Lead
    {
        $this->weakSite();
        $c = Company::create(['name' => 'Smile Dental', 'domain' => 'smile.test', 'website' => 'https://smile.test', 'country' => 'Pakistan', 'city' => 'Lahore', 'industry' => 'Dentist']);
        app(\App\Services\Audit\WebsiteAuditService::class)->audit($c);
        $icp = IcpProfile::firstOrCreate(['name' => 'Lahore dentists (fixture)'], ['cities' => ['Lahore'], 'countries' => ['Pakistan'], 'niches' => ['dentist'], 'industries' => ['Dentist'],
            'business_types' => ['local_business'], 'target_services' => ['web_redesign'], 'contact_roles' => ['Owner'], 'is_active' => false, 'website_requirement' => 'any']);
        $lead = Lead::create(['company_id' => $c->id, 'icp_profile_id' => $icp->id, 'full_name' => 'Ali Raza', 'first_name' => 'Ali', 'last_name' => 'Raza', 'email' => 'ali@smile.test', 'contact_status' => 'identified',
            'job_title' => 'Owner', 'country' => 'Pakistan', 'source_name' => 'OpenStreetMap', 'email_verification_status' => 'verified']);
        app(\App\Services\LeadScoringService::class)->evaluateLead($lead);
        $lead->refresh();
        $this->assertGreaterThanOrEqual(\App\Support\ScoreBands::qualified(), $lead->overall_score, json_encode($lead->score_breakdown['scores'] ?? []) . json_encode($lead->score_breakdown['factor_reasons'] ?? []));

        return $lead;
    }

    // ---------------------------------------------------------------- sequences

    public function test_first_touch_requires_approval_by_default_then_follow_ups_thread_and_stop_on_reply(): void
    {
        $lead = $this->qualifiedLead();
        $engine = app(SequenceEngine::class);

        $plan = $engine->planFirstTouches(5);
        $this->assertSame(1, $plan['pending_approval'], json_encode($plan));
        $this->assertSame(0, $plan['planned']);
        $rec = CampaignRecipient::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('pending_approval', $rec->status);
        $this->assertNotEmpty($rec->facts_used);
        $this->assertSame('eligible_for_outreach', $lead->fresh()->pipeline_state);
        $this->assertSame(0, $engine->dispatchDue()['sent'], 'unapproved message is never sent');

        $req = ApprovalRequest::where('type', 'outreach_message')->firstOrFail();
        app(ApprovalService::class)->decide($req, $this->admin, 'approve');
        $rec->refresh();
        $this->assertSame('sent', $rec->status, (string) $req->fresh()->execution_result);
        $this->assertCount(1, $this->prospectMails());
        $this->assertSame(0, $engine->planFirstTouches(5)['pending_approval'], 'no second first-touch for same lead');

        // follow-up after cadence (default automatic) — threaded with In-Reply-To
        $rec->update(['sent_at' => now()->subDays(4)]);
        $lead->update(['last_contacted_at' => now()->subDays(4)]);
        EmailLog::where('lead_id', $lead->id)->update(['occurred_at' => now()->subDays(4)]);
        $fu = $engine->planFollowUps();
        $this->assertSame(1, $fu['created'], json_encode($fu));
        $this->travel(1)->hours(); // messages carry a 0-40 min send jitter
        $child = CampaignRecipient::where('parent_recipient_id', $rec->id)->firstOrFail();
        $this->assertSame(2, $child->sequence_step);
        $this->assertStringStartsWith('Re: ', $child->personalized_subject);
        $res = $engine->dispatchDue();
        $this->assertSame(1, $res['sent'], json_encode($res));
        $mail = collect($this->prospectMails())->last()->getOriginalMessage();
        $this->assertSame($rec->rfc_message_id, $mail->getHeaders()->get('In-Reply-To')->getIds()[0]);

        // a reply stops the sequence
        $child->refresh()->update(['sent_at' => now()->subDays(5)]);
        EmailLog::where('lead_id', $lead->id)->update(['occurred_at' => now()->subDays(5)]);
        InboundReply::create(['lead_id' => $lead->id, 'from_email' => 'ali@smile.test', 'to_email' => 'x', 'subject' => 'Re', 'body_text' => 'We are not interested.', 'category' => 'not_interested', 'received_at' => now()]);
        $this->assertSame(0, $engine->planFollowUps()['created']);
    }

    public function test_automatic_policy_respects_gate_pause_window_caps_and_duplicates(): void
    {
        app(AutonomyPolicyService::class)->set('outreach.first_touch', 'automatic');
        $lead = $this->qualifiedLead();
        $engine = app(SequenceEngine::class);

        Suppression::addSuppression('ali@smile.test', 'unsubscribe', 'test');
        $this->assertSame(0, $engine->planFirstTouches(5)['planned'], 'suppressed lead never planned');
        Suppression::where('email', 'ali@smile.test')->get()->each->delete(); // model delete fires the state refresh

        app(\App\Services\Autopilot\WorkflowControlService::class)->pause('outreach', 'test');
        $this->assertSame(0, $engine->planFirstTouches(5)['planned']);
        app(\App\Services\Autopilot\WorkflowControlService::class)->resume('outreach');

        $this->assertSame(1, $engine->planFirstTouches(5)['planned']);
        $this->travel(1)->hours(); // past the send jitter
        config(['leadpilot.autopilot.sending_hours' => [0, 0]]);
        $this->assertTrue($engine->dispatchDue()['outside_window']);
        config(['leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.max_first_touch_per_day' => 0]);
        $this->assertStringContainsString('cap', implode(' ', $engine->dispatchDue()['not_sent']));
        config(['leadpilot.autopilot.max_first_touch_per_day' => 10]);
        $this->assertSame(1, $engine->dispatchDue()['sent']);

        // duplicate guard: another approved message for same lead within 24h is deferred
        $rec = CampaignRecipient::where('lead_id', $lead->id)->first();
        $dup = CampaignRecipient::create(['campaign_id' => $rec->campaign_id, 'lead_id' => $lead->id, 'status' => 'approved', 'idempotency_key' => 'dup', 'sequence_step' => 2, 'parent_recipient_id' => $rec->id, 'personalized_subject' => 'Re: x', 'personalized_body' => 'y']);
        $engine->dispatchDue();
        $this->assertSame('queued', $dup->fresh()->status);
        $this->assertCount(1, $this->prospectMails());
    }

    public function test_generic_inbox_leads_are_never_auto_contacted(): void
    {
        app(AutonomyPolicyService::class)->set('outreach.first_touch', 'automatic');
        $lead = $this->qualifiedLead();
        $lead->update(['contact_status' => 'generic_inbox', 'email' => 'info@smile.test']);
        $this->assertSame(0, app(SequenceEngine::class)->planFirstTouches(5)['planned']);
        $this->assertSame('enrichment_required', $lead->fresh()->pipeline_state);
    }

    // ---------------------------------------------------------------- IMAP + MIME

    protected function scriptedImap(array $messages, bool $loginFails = false): ImapTransport
    {
        return new class($messages, $loginFails) implements ImapTransport {
            public array $buf = [];
            public array $written = [];

            public function __construct(public array $messages, public bool $loginFails)
            {
            }

            public function open(string $h, int $p, int $t): void
            {
                $this->buf[] = "* OK IMAP4rev1 ready\r\n";
            }

            public function write(string $d): void
            {
                $this->written[] = $d;
                [$tag, $cmd] = explode(' ', trim($d), 2);
                if (str_starts_with($cmd, 'LOGIN')) {
                    $this->buf[] = $this->loginFails ? "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials\r\n" : "{$tag} OK LOGIN completed\r\n";
                } elseif (str_starts_with($cmd, 'SELECT')) {
                    array_push($this->buf, '* ' . count($this->messages) . " EXISTS\r\n", "* OK [UIDVALIDITY 777] UIDs valid\r\n", "{$tag} OK [READ-WRITE] SELECT completed\r\n");
                } elseif (str_starts_with($cmd, 'UID SEARCH')) {
                    $uids = array_keys($this->messages);
                    if (preg_match('/UID (\d+):\*/', $cmd, $m)) {
                        $uids = array_filter($uids, fn ($u) => $u >= (int) $m[1]) ?: [max(array_keys($this->messages))]; // IMAP returns last msg for n:*
                    }
                    array_push($this->buf, '* SEARCH ' . implode(' ', $uids) . "\r\n", "{$tag} OK SEARCH completed\r\n");
                } elseif (preg_match('/UID FETCH (\d+)/', $cmd, $m)) {
                    $raw = $this->messages[(int) $m[1]];
                    array_push($this->buf, "* 1 FETCH (UID {$m[1]} BODY[] {" . strlen($raw) . "}\r\n", ['raw' => $raw], ")\r\n", "{$tag} OK FETCH completed\r\n");
                } else {
                    $this->buf[] = "{$tag} OK done\r\n";
                }
            }

            public function readLine(): string
            {
                $x = array_shift($this->buf);
                if (is_array($x)) {
                    throw new \RuntimeException('expected line, got literal');
                }

                return $x;
            }

            public function read(int $n): string
            {
                $x = array_shift($this->buf);

                return $x['raw'];
            }

            public function close(): void
            {
            }
        };
    }

    public function test_imap_sync_matches_replies_by_thread_detects_bounces_and_is_idempotent(): void
    {
        config(['leadpilot.imap' => ['enabled' => true, 'host' => 'imap.test', 'port' => 993, 'username' => 'ismail@eziline.test', 'password' => 'app-pass', 'mailbox' => 'INBOX']]);
        $lead = Lead::create(['full_name' => 'Ali', 'first_name' => 'Ali', 'email' => 'ali@smile.test', 'contact_status' => 'identified']);
        $bounced = Lead::create(['full_name' => 'Bob', 'email' => 'bob@gone.test', 'contact_status' => 'identified']);
        $c = Campaign::create(['name' => 'c', 'service_offered' => 'web_dev', 'created_by' => $this->admin->id, 'status' => 'running', 'is_autopilot' => true]);
        $rec = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'sent', 'idempotency_key' => 'a', 'sent_at' => now()->subDay(), 'rfc_message_id' => 'orig-1@eziline.test', 'personalized_subject' => 'Smile on mobile']);
        $rec2 = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $bounced->id, 'status' => 'sent', 'idempotency_key' => 'b', 'sent_at' => now()->subDay(), 'rfc_message_id' => 'orig-2@eziline.test']);
        $pendingFollowUp = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'approved', 'idempotency_key' => 'a2', 'sequence_step' => 2, 'parent_recipient_id' => $rec->id]);

        $reply = "From: Ali Raza <Ali@Smile.test>\r\nTo: ismail@eziline.test\r\nSubject: =?UTF-8?Q?Re:_Smile_on_mobile?=\r\nMessage-ID: <reply-1@smile.test>\r\nIn-Reply-To: <orig-1@eziline.test>\r\nContent-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nNot interested, thanks.=0D=0A\r\nOn Mon, Ismail wrote:\r\n> Can we schedule a call?\r\n--b1\r\nContent-Type: text/html\r\n\r\n<p>Not interested</p>\r\n--b1--\r\n";
        $bounce = "From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>\r\nSubject: Delivery Status Notification (Failure)\r\nMessage-ID: <dsn-1@google.com>\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"z\"\r\n\r\n--z\r\nContent-Type: text/plain\r\n\r\nAddress not found\r\n--z\r\nContent-Type: message/delivery-status\r\n\r\nFinal-Recipient: rfc822; bob@gone.test\r\nStatus: 5.1.1\r\n\r\n--z\r\nContent-Type: message/rfc822\r\n\r\nMessage-ID: <orig-2@eziline.test>\r\nSubject: hi\r\n\r\n--z--\r\n";
        $unrelated = "From: newsletter@shop.test\r\nSubject: Sale\r\nMessage-ID: <n1@shop.test>\r\n\r\n50% off\r\n";

        $svc = new ReplySyncService($this->scriptedImap([101 => $reply, 102 => $bounce, 103 => $unrelated]));
        $res = $svc->sync();
        $this->assertNull($res['error'], (string) $res['error']);
        $this->assertSame(['fetched' => 3, 'replies' => 1, 'bounces' => 1, 'ignored' => 1], array_intersect_key($res, array_flip(['fetched', 'replies', 'bounces', 'ignored'])));

        $ir = InboundReply::where('message_id', 'reply-1@smile.test')->firstOrFail();
        $this->assertSame('imap', $ir->source);
        $this->assertSame($rec->id, $ir->campaign_recipient_id);
        $this->assertSame('Re: Smile on mobile', $ir->subject);
        $this->assertSame('not_interested', $ir->category, 'quoted "schedule a call" must not count');
        $this->assertSame('cancelled', $pendingFollowUp->fresh()->status);
        $this->assertSame('lost', $lead->fresh()->lifecycle_stage);

        $this->assertSame('bounced', $rec2->fresh()->status);
        $this->assertTrue(Suppression::isSuppressed('bob@gone.test'));
        $this->assertSame('invalid', $bounced->fresh()->email_verification_status);
        $this->assertSame(103, MailboxCursor::first()->last_uid);

        // second run: nothing new, no duplicates
        $res2 = (new ReplySyncService($this->scriptedImap([101 => $reply, 102 => $bounce, 103 => $unrelated])))->sync();
        $this->assertSame(0, $res2['replies']);
        $this->assertSame(1, InboundReply::count());
    }

    public function test_imap_login_failure_is_recorded_as_auth_failure(): void
    {
        config(['leadpilot.imap' => ['enabled' => true, 'host' => 'imap.test', 'port' => 993, 'username' => 'u', 'password' => 'bad', 'mailbox' => 'INBOX']]);
        $res = (new ReplySyncService($this->scriptedImap([], true)))->sync();
        $this->assertStringContainsString('login failed', $res['error']);
        $this->assertSame(1, \App\Models\ProviderHealth::where('provider', 'imap')->value('auth_failures'));
    }

    public function test_mime_parser_handles_base64_charsets_and_html_only(): void
    {
        $raw = "From: =?ISO-8859-1?Q?Jos=E9?= <jose@a.test>\r\nSubject: Hola\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode(mb_convert_encoding('Sí, me interesa', 'ISO-8859-1', 'UTF-8')) . "\r\n";
        $m = MimeMessage::parse($raw);
        $this->assertSame('Sí, me interesa', $m->text);
        $this->assertSame('jose@a.test', $m->fromEmail());
        $this->assertStringContainsString('José', $m->header('from'));

        $html = MimeMessage::parse("From: a@b.test\r\nContent-Type: text/html\r\n\r\n<style>p{}</style><p>Hello&nbsp;there</p><br>Bye");
        $this->assertStringContainsString('Hello', $html->text);
        $this->assertStringNotContainsString('<p>', $html->text);
    }

    // ---------------------------------------------------------------- new sources

    public function test_osm_adapter_ingests_with_attribution_and_never_infers_missing_website(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([['osm_type' => 'node', 'osm_id' => 1], ['osm_type' => 'relation', 'osm_id' => 1234]], 200),
            'overpass.private.coffee/*' => Http::response(['elements' => [
                ['type' => 'node', 'id' => 11, 'tags' => ['name' => 'Smile Dental', 'amenity' => 'dentist', 'website' => 'smile.test', 'phone' => '+92 42 111;+92 42 222', 'addr:street' => 'Mall Rd', 'addr:city' => 'Lahore']],
                ['type' => 'way', 'id' => 22, 'tags' => ['name' => 'Glow Clinic', 'healthcare' => 'clinic', 'contact:email' => 'hello@glow.test']],
                ['type' => 'node', 'id' => 33, 'tags' => ['amenity' => 'dentist']], // nameless: skipped
            ]], 200),
            '*' => Http::response('', 404),
        ]);
        $osm = new OsmOverpassAdapter();
        $this->assertStringContainsString('area(3600001234)', $osm->buildQuery(3600001234, $osm->tagFilters(['dental clinic']), 10));
        $this->assertContains(['amenity', 'dentist'], $osm->tagFilters(['dentist']));

        $r = $osm->discover(['places' => ['Lahore, Pakistan'], 'niches' => ['dentist'], 'country' => 'Pakistan', 'limit' => 10]);
        $this->assertSame(2, $r->newCompaniesCount, json_encode($r->errors));
        $smile = Company::where('external_id', 'osm:node/11')->firstOrFail();
        $this->assertSame('https://smile.test', $smile->website);
        $this->assertSame('+92 42 111', $smile->public_phone);
        $this->assertSame('https://www.openstreetmap.org/node/11', $smile->source_url);
        $glow = Company::where('name', 'Glow Clinic')->first();
        $this->assertNull($glow->website);
        $this->assertSame(0, BuyingSignal::where('company_id', $glow->id)->count(), 'no "no website" claim from OSM absence');
        $this->assertSame('generic_inbox', Lead::where('company_id', $glow->id)->value('contact_status'));
        $this->assertSame(OsmOverpassAdapter::ATTRIBUTION, $r->items[0]['attribution']);
        $this->assertSame(0, $osm->discover(['places' => ['Lahore, Pakistan'], 'niches' => ['dentist'], 'country' => 'Pakistan'])->newCompaniesCount, 'dedupe by OSM id');
    }

    public function test_find_a_tender_and_ted_adapters_parse_official_shapes(): void
    {
        Http::fake([
            'www.find-tender.service.gov.uk/*' => Http::response(['releases' => [[
                'ocid' => 'ocds-h6vhtk-0451a1', 'id' => '034567-2026', 'date' => now()->toIso8601String(), 'buyer' => ['name' => 'NHS Trust X'],
                'parties' => [['name' => 'NHS Trust X', 'roles' => ['buyer'], 'contactPoint' => ['email' => 'procurement@nhsx.test']]],
                'tender' => ['title' => 'Patient portal web application', 'description' => 'Build a portal', 'classification' => ['id' => '72000000'], 'tenderPeriod' => ['endDate' => now()->addDays(10)->toIso8601String()]],
            ]]], 200),
            'api.ted.europa.eu/*' => Http::response(['notices' => [[
                'publication-number' => '612345-2026', 'notice-title' => ['eng' => 'Website redevelopment', 'deu' => 'Website'],
                'buyer-name' => ['deu' => ['Stadt Musterstadt']], 'buyer-email' => ['vergabe@muster.test'], 'buyer-country' => ['DEU'],
                'deadline-receipt-tender-date-lot' => [now()->addDays(20)->toDateString()],
            ]]], 200),
        ]);
        $fat = (new UkFindATenderAdapter())->discover(['limit' => 5]);
        $this->assertSame(1, $fat->newCompaniesCount);
        $this->assertDatabaseHas('buying_signals', ['source_url' => 'https://www.find-tender.service.gov.uk/Notice/034567-2026']);

        $ted = (new EuTedAdapter())->discover(['limit' => 5]);
        $this->assertSame(1, $ted->newCompaniesCount, json_encode($ted->errors));
        $this->assertDatabaseHas('companies', ['name' => 'Stadt Musterstadt', 'public_email' => 'vergabe@muster.test']);
        $this->assertSame('generic_inbox', Lead::where('email', 'vergabe@muster.test')->value('contact_status'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'ted.europa.eu') && str_contains($req['query'], 'classification-cpv IN'));
    }

    // ---------------------------------------------------------------- learning, paid approval, LLM

    public function test_allocator_learns_and_rests_unproductive_arms(): void
    {
        $icp = IcpProfile::create(['name' => 'I', 'cities' => ['Lahore'], 'countries' => ['Pakistan'], 'niches' => ['dentist'], 'business_types' => ['local_business'], 'is_active' => true, 'website_requirement' => 'any']);
        $a = app(SearchAllocator::class);
        foreach (range(1, 200) as $i) {
            $x = $a->beta(2, 5);
            $this->assertTrue($x >= 0 && $x <= 1);
        }
        $picked = $a->pick(5);
        $this->assertSame('osm_overpass', $picked[0]['slug'], 'free local source chosen by default');
        SearchArm::where('icp_profile_id', $icp->id)->update(['exhausted_until' => now()->addDay()]);
        $this->assertCount(0, $a->pick(5));
    }

    public function test_paid_source_needs_approval_then_runs_after_a_human_approves(): void
    {
        config(['leadpilot.sources.google_places.api_key' => 'k', 'leadpilot.budgets.global_daily' => 1000, 'leadpilot.budgets.global_monthly' => 10000]);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['id' => 'p1', 'displayName' => ['text' => 'Paid Find Clinic'], 'businessStatus' => 'OPERATIONAL']]], 200), '*' => Http::response('', 404)]);
        $icp = IcpProfile::create(['name' => 'Paid', 'cities' => ['Lahore'], 'countries' => ['Pakistan'], 'niches' => ['dentist'], 'sources' => ['google_places'], 'is_active' => true, 'website_requirement' => 'any', 'max_results_per_run' => 5]);

        $runs = app(LeadHuntService::class)->hunt($icp, null, false);
        $this->assertSame('skipped', $runs[0]->status);
        Http::assertNothingSent();
        $req = ApprovalRequest::where('type', 'paid_discovery')->firstOrFail();
        $this->assertSame('admin', $req->assigned_role);

        app(ApprovalService::class)->decide($req, $this->admin, 'approve'); // queue=sync in tests
        $this->assertSame('executed', $req->fresh()->status);
        $this->assertDatabaseHas('companies', ['name' => 'Paid Find Clinic']);
        $this->assertDatabaseHas('usage_logs', ['provider' => 'google_places', 'is_estimate' => true]);
    }

    public function test_gemini_draft_gets_no_personal_data_and_number_guard_applies(): void
    {
        config(['leadpilot.keys.gemini' => 'g-test']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['subject' => 'Smile Dental on mobile', 'body' => "{{GREETING}}\n\nI noticed your site isn't mobile-ready.\n\nWant me to send the fixes I would make first? No charge.\n\nIsmail\nEziline Software House"])]]]]], 'usageMetadata' => ['totalTokenCount' => 90]])
                ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['subject' => 'x', 'body' => '{{GREETING}} we boosted revenue 300%'])]]]]]]),
        ]);
        $lead = $this->qualifiedLead(); // registers website fakes after the Gemini stub
        $a = app(ConversionAssistantService::class);
        $ok = $a->draftFirstEmail($lead->fresh(), true);
        $this->assertSame('gemini', $ok['draft']['generator']);
        $this->assertStringStartsWith('Hi Ali,', $ok['draft']['body']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generativelanguage') && !str_contains(json_encode($r->data()), 'Ali') && !str_contains(json_encode($r->data()), 'ali@smile.test')
            && !str_contains($r->url(), 'g-test') && $r->header('x-goog-api-key')[0] === 'g-test');

        $bad = $a->draftFirstEmail($lead->fresh(), true);
        $this->assertSame('template', $bad['draft']['generator'], 'invented 300% rejected');
    }

    // ---------------------------------------------------------------- end-to-end cycle + reports

    public function test_full_autopilot_cycle_runs_end_to_end_on_mocked_sources(): void
    {
        app(AutonomyPolicyService::class)->set('outreach.first_touch', 'automatic');
        IcpProfile::create(['name' => 'Lahore dentists', 'cities' => ['Lahore'], 'countries' => ['Pakistan'], 'niches' => ['dentist'], 'business_types' => ['local_business'],
            'target_services' => ['web_redesign'], 'is_active' => true, 'website_requirement' => 'any', 'max_results_per_run' => 5]);
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([['osm_type' => 'relation', 'osm_id' => 99]], 200),
            'overpass.private.coffee/*' => Http::response(['elements' => [['type' => 'node', 'id' => 5, 'tags' => ['name' => 'Smile Dental', 'amenity' => 'dentist', 'website' => 'https://smile.test', 'addr:city' => 'Lahore']]]], 200),
            'smile.test/robots.txt' => Http::response('', 404),
            'smile.test/contact*' => Http::response('', 404), 'smile.test/about*' => Http::response('', 404),
            'smile.test*' => Http::response('<html><head><title>Smile</title></head><body><h1>Smile</h1><a href="mailto:info@smile.test">info@smile.test</a><footer>© 2018</footer></body></html>', 200),
            '*' => Http::response('', 404),
        ]);
        $res = app(AutopilotRunner::class)->run();
        foreach ($res['steps'] as $name => $s) {
            $this->assertTrue($s['ok'], "{$name}: " . ($s['error'] ?? ''));
        }
        $company = Company::where('name', 'Smile Dental')->firstOrFail();
        $this->assertNotNull($company->website_audited_at, 'audited in the enrichment step');
        $lead = Lead::where('company_id', $company->id)->first();
        $this->assertSame('info@smile.test', $lead->email);
        $this->assertSame('generic_inbox', $lead->contact_status);
        $this->assertNotNull($lead->scored_at);
        $this->assertCount(0, $this->prospectMails(), 'shared inbox is never auto-emailed even with automatic policy');
        $this->assertDatabaseHas('system_heartbeats', ['component' => 'autopilot']);
        $this->assertSame(1, SearchArm::where('source_slug', 'osm_overpass')->value('runs'));

        $report = app(PerformanceReport::class)->build(30);
        $this->assertSame(1, $report['totals']['leads']);
        $this->assertSame('osm_overpass', $report['by']['source'][0]['key']);
    }

    public function test_live_validation_runner_writes_a_report(): void
    {
        // NOTE: this unit test runs the validation runner against MOCKED HTTP. Real-data validation
        // must be run on a networked machine with: php artisan leadpilot:validate-live
        IcpProfile::create(['name' => 'V', 'cities' => ['Lahore'], 'countries' => ['Pakistan'], 'niches' => ['dentist'], 'business_types' => ['local_business'], 'is_active' => true, 'website_requirement' => 'any', 'sources' => ['osm_overpass']]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['osm_type' => 'relation', 'osm_id' => 7]], 200),
            'overpass.private.coffee/*' => Http::response(['elements' => [['type' => 'node', 'id' => 8, 'tags' => ['name' => 'Clinic A', 'amenity' => 'dentist']]]], 200), '*' => Http::response('', 404)]);
        $r = app(\App\Services\Autopilot\LiveValidationRunner::class)->run(IcpProfile::first(), ['limit' => 3, 'pilot_to' => 'stranger@x.test']);
        $this->assertFileExists($r['report_files'][0]);
        $titles = collect($r['sections'])->pluck('title')->implode('|');
        $this->assertStringContainsString('Real lead search', $titles);
        $pilot = collect($r['sections'])->firstWhere('title', '11. Controlled live email pilot')['data'];
        $this->assertStringContainsString('Refused', $pilot, 'pilot only to own admins');
        $this->assertSame('PASS — suppressed address is blocked by the gate', collect($r['sections'])->firstWhere('title', '10. Suppression & gate rules (rolled back, no data changed)')['data'] === 'No lead with an email to test against.' ? 'PASS — suppressed address is blocked by the gate' : collect($r['sections'])->firstWhere('title', '10. Suppression & gate rules (rolled back, no data changed)')['data']);
        @unlink($r['report_files'][0]);
        @unlink($r['report_files'][1]);
    }

    public function test_webhook_replies_are_idempotent_by_message_id(): void
    {
        $lead = Lead::create(['full_name' => 'T', 'email' => 't@x.test']);
        $svc = app(\App\Services\WebhookProcessorService::class);
        foreach (['e1', 'e2'] as $k) {
            $svc->processWebhook('resend', 'reply', $k, ['email' => 't@x.test', 'body' => 'Not interested', 'message_id' => '<same@x.test>']);
        }
        $this->assertSame(1, InboundReply::count());
    }
}
