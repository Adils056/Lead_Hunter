<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_waiting_work_and_unanswered_replies_without_addresses(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $c = Company::create(['name' => 'Kre8tive Nails']);
        $lead = Lead::create(['company_id' => $c->id, 'full_name' => 'Kre8tive Nails — general inbox', 'email' => 'owner@kre8tive.example', 'contact_status' => 'generic_inbox']);
        InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'us@x.example', 'subject' => 'Re: hi', 'body_text' => 'Yes, interested', 'category' => 'interested', 'received_at' => now()->subDays(2)]);
        Task::create(['lead_id' => $lead->id, 'company_id' => $c->id, 'assigned_to' => $admin->id, 'title' => 'Answer now', 'status' => 'pending', 'priority' => 'high', 'due_date' => now()->subDays(3)->toDateString()]);
        ApprovalRequest::create(['type' => 'outreach_message', 'dedupe_key' => 'x1', 'title' => 'First e-mail', 'summary' => 's', 'status' => 'pending']);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('leadpilot:reliability'));
        $out = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

        $this->assertSame(1, $out['tasks_overdue']);
        $this->assertSame(['outreach_message' => 1], $out['approvals_open_by_type']);
        $this->assertStringContainsString('interested | Kre8tive Nails', $out['email_replies_30d'][0]);
        $this->assertStringContainsString('answered by e-mail no', $out['email_replies_30d'][0]);
        $this->assertStringNotContainsString('owner@kre8tive.example', json_encode($out));
    }
}
