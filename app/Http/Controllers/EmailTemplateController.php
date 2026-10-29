<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Support\ServiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Email templates for manual campaigns (autopilot writes its own evidence-based drafts). */
class EmailTemplateController extends Controller
{
    public const VARIABLES = ['first_name', 'contact_name', 'company_name', 'verified_industry', 'relevant_service', 'sender_name', 'agency_website', 'opt_out_link'];

    /** Starter templates the first time the page (or Create Campaign) is opened on an empty install. */
    public static function ensureStarters(): void
    {
        if (!EmailTemplate::exists()) {
            (new \Database\Seeders\EmailTemplateSeeder())->run();
        }
    }

    public function index()
    {
        self::ensureStarters();
        $templates = EmailTemplate::withCount('campaigns')->orderByDesc('is_active')->orderBy('name')->get();

        return view('templates.index', ['templates' => $templates, 'services' => ServiceCatalog::all()]);
    }

    public function create()
    {
        $this->adminOnly();

        return view('templates.form', ['template' => new EmailTemplate(['is_active' => true]), 'services' => ServiceCatalog::all(), 'variables' => self::VARIABLES]);
    }

    public function store(Request $request)
    {
        $this->adminOnly();
        $data = $this->validated($request);
        $data['slug'] = Str::slug($data['name']) . '-' . Str::lower(Str::random(5));
        $data['created_by'] = Auth::id();
        $template = EmailTemplate::create($data);
        AuditLog::record('email_template_created', $template);

        return redirect()->route('templates.index')->with('success', "Template '{$template->name}' created.");
    }

    public function edit(EmailTemplate $template)
    {
        $this->adminOnly();

        return view('templates.form', ['template' => $template, 'services' => ServiceCatalog::all(), 'variables' => self::VARIABLES]);
    }

    public function update(Request $request, EmailTemplate $template)
    {
        $this->adminOnly();
        $template->update($this->validated($request));
        AuditLog::record('email_template_updated', $template);

        return redirect()->route('templates.index')->with('success', "Template '{$template->name}' saved.");
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'service_category' => ['required', 'string', 'in:' . implode(',', array_keys(ServiceCatalog::all()))],
            'subject' => ['required', 'string', 'max:255'],
            'body_text' => ['required', 'string', 'max:10000'],
        ], [
            'body_text.required' => 'Write the email body.',
        ]);
        if (!str_contains($data['body_text'], '{{opt_out_link}}') && !str_contains($data['body_text'], '{{ opt_out_link }}')) {
            $data['body_text'] = rtrim($data['body_text']) . "\n\nOpt-out: {{opt_out_link}}"; // every cold email carries an unsubscribe link
        }
        $data['body_html'] = nl2br(e($data['body_text']), false);
        $data['available_variables'] = array_values(array_filter(self::VARIABLES, fn ($v) => str_contains($data['subject'] . $data['body_text'], $v)));
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    protected function adminOnly(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403, 'Only an admin can change email templates.');
    }
}
