<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();

        $templates = [
            [
                'name' => 'Laravel & PHP Custom Development',
                'slug' => 'laravel-php-development',
                'service_category' => 'laravel_php',
                'subject' => 'Scaling {{company_name}}\'s backend engineering with dedicated Laravel talent',
                'body_html' => '<p>Hi {{first_name}},</p>
<p>I noticed that {{company_name}} operates in the {{verified_industry}} space and is currently scaling your software infrastructure. We specialize in enterprise Laravel and PHP application architecture, having built resilient APIs, multi-tenant SaaS platforms, and high-load web systems.</p>
<p>Whether you need senior Laravel engineers to accelerate your roadmap or optimize existing system performance, we provide dedicated engineering bandwidth with zero onboarding friction.</p>
<p>Would you be open to a quick 15-minute conversation next Tuesday to explore if our technical team could support {{company_name}}?</p>
<p>Best regards,<br><strong>{{sender_name}}</strong><br>{{agency_website}}</p>
<p style="font-size: 11px; color: #888;">If you prefer not to receive future updates, please <a href="{{opt_out_link}}">unsubscribe here</a>.</p>',
                'body_text' => "Hi {{first_name}},\n\nI noticed that {{company_name}} operates in the {{verified_industry}} space and is currently scaling your software infrastructure. We specialize in enterprise Laravel and PHP application architecture, having built resilient APIs, multi-tenant SaaS platforms, and high-load web systems.\n\nWhether you need senior Laravel engineers to accelerate your roadmap or optimize existing system performance, we provide dedicated engineering bandwidth with zero onboarding friction.\n\nWould you be open to a quick 15-minute conversation next Tuesday to explore if our technical team could support {{company_name}}?\n\nBest regards,\n{{sender_name}}\n{{agency_website}}\n\nOpt-out: {{opt_out_link}}",
                'available_variables' => ['first_name', 'company_name', 'verified_industry', 'sender_name', 'agency_website', 'opt_out_link'],
                'is_active' => true,
                'created_by' => $admin?->id,
            ],
            [
                'name' => 'Custom ERP & CRM Solutions',
                'slug' => 'custom-erp-crm',
                'service_category' => 'erp_pos',
                'subject' => 'Streamlining internal operations & workflow automation for {{company_name}}',
                'body_html' => '<p>Hello {{first_name}},</p>
<p>Off-the-shelf software often forces businesses into rigid workflows that don\'t match how teams actually operate. We design and engineer tailored ERP and CRM systems built around your company\'s exact operational models, invoicing pipelines, and inventory workflows.</p>
<p>Given {{company_name}}\'s growth in {{verified_industry}}, having centralized data visibility and automated cross-department handoffs can cut manual processing overhead significantly.</p>
<p>Could we schedule a brief introductory call this week to review your current toolchain and see where tailored software could create an edge?</p>
<p>Regards,<br><strong>{{sender_name}}</strong><br>{{agency_website}}</p>
<p style="font-size: 11px; color: #888;"><a href="{{opt_out_link}}">Opt out of future communications</a></p>',
                'body_text' => "Hello {{first_name}},\n\nOff-the-shelf software often forces businesses into rigid workflows that don't match how teams actually operate. We design and engineer tailored ERP and CRM systems built around your company's exact operational models, invoicing pipelines, and inventory workflows.\n\nGiven {{company_name}}'s growth in {{verified_industry}}, having centralized data visibility and automated cross-department handoffs can cut manual processing overhead significantly.\n\nCould we schedule a brief introductory call this week to review your current toolchain and see where tailored software could create an edge?\n\nRegards,\n{{sender_name}}\n{{agency_website}}\n\nOpt-out: {{opt_out_link}}",
                'available_variables' => ['first_name', 'company_name', 'verified_industry', 'sender_name', 'agency_website', 'opt_out_link'],
                'is_active' => true,
                'created_by' => $admin?->id,
            ],
            [
                'name' => 'E-Commerce Platform Modernization',
                'slug' => 'ecommerce-development',
                'subject' => 'Modernizing checkout & performance for {{company_name}}',
                'service_category' => 'ecommerce',
                'body_html' => '<p>Hi {{first_name}},</p>
<p>Cart abandonment and sluggish mobile checkout speeds are the top revenue bottlenecks for growing online stores. We engineer ultra-fast, headless and custom e-commerce experiences with seamless payment gateway integrations, real-time inventory synchronization, and high-converting checkout flows.</p>
<p>Are you evaluating any updates to {{company_name}}\'s digital storefront this quarter?</p>
<p>Best regards,<br><strong>{{sender_name}}</strong><br>{{agency_website}}</p>
<p style="font-size: 11px; color: #888;"><a href="{{opt_out_link}}">Unsubscribe</a></p>',
                'body_text' => "Hi {{first_name}},\n\nCart abandonment and sluggish mobile checkout speeds are the top revenue bottlenecks for growing online stores. We engineer ultra-fast, headless and custom e-commerce experiences with seamless payment gateway integrations, real-time inventory synchronization, and high-converting checkout flows.\n\nAre you evaluating any updates to {{company_name}}'s digital storefront this quarter?\n\nBest regards,\n{{sender_name}}\n{{agency_website}}\n\nOpt-out: {{opt_out_link}}",
                'available_variables' => ['first_name', 'company_name', 'sender_name', 'agency_website', 'opt_out_link'],
                'is_active' => true,
                'created_by' => $admin?->id,
            ],
            [
                'name' => 'API Integrations & Workflow Automation',
                'slug' => 'api-integrations-automation',
                'service_category' => 'api_integration',
                'subject' => 'Eliminating duplicate data entry between {{company_name}}\'s software systems',
                'body_html' => '<p>Hello {{first_name}},</p>
<p>Managing disparate software tools often results in manual spreadsheets and fragmented customer data. We build secure, bidirectional API integrations, webhooks, and automated background synchronization pipelines between payment processors, CRMs, ERPs, and cloud databases.</p>
<p>If you have systems that aren\'t communicating smoothly, our integration specialists can unify them reliably.</p>
<p>Let me know if you would appreciate a 10-minute discovery call.</p>
<p>Best,<br><strong>{{sender_name}}</strong><br>{{agency_website}}</p>
<p style="font-size: 11px; color: #888;"><a href="{{opt_out_link}}">Unsubscribe</a></p>',
                'body_text' => "Hello {{first_name}},\n\nManaging disparate software tools often results in manual spreadsheets and fragmented customer data. We build secure, bidirectional API integrations, webhooks, and automated background synchronization pipelines between payment processors, CRMs, ERPs, and cloud databases.\n\nIf you have systems that aren't communicating smoothly, our integration specialists can unify them reliably.\n\nLet me know if you would appreciate a 10-minute discovery call.\n\nBest,\n{{sender_name}}\n{{agency_website}}\n\nOpt-out: {{opt_out_link}}",
                'available_variables' => ['first_name', 'company_name', 'sender_name', 'agency_website', 'opt_out_link'],
                'is_active' => true,
                'created_by' => $admin?->id,
            ],
            [
                'name' => 'SaaS Product Development & MVP Build',
                'slug' => 'saas-product-development',
                'service_category' => 'saas_mvp',
                'subject' => 'Architecting {{company_name}}\'s next software release',
                'body_html' => '<p>Hi {{first_name}},</p>
<p>Taking a SaaS product from concept to production-ready scale requires battle-tested architecture: clean multi-tenancy, Stripe subscription billing, robust queue workers, role permissions, and airtight security.</p>
<p>Our team works as a technical co-development partner for funded startups and mid-market companies building modern web applications.</p>
<p>Do you have 15 minutes this week to share your product vision?</p>
<p>Warm regards,<br><strong>{{sender_name}}</strong><br>{{agency_website}}</p>
<p style="font-size: 11px; color: #888;"><a href="{{opt_out_link}}">Unsubscribe</a></p>',
                'body_text' => "Hi {{first_name}},\n\nTaking a SaaS product from concept to production-ready scale requires battle-tested architecture: clean multi-tenancy, Stripe subscription billing, robust queue workers, role permissions, and airtight security.\n\nOur team works as a technical co-development partner for funded startups and mid-market companies building modern web applications.\n\nDo you have 15 minutes this week to share your product vision?\n\nWarm regards,\n{{sender_name}}\n{{agency_website}}\n\nOpt-out: {{opt_out_link}}",
                'available_variables' => ['first_name', 'company_name', 'sender_name', 'agency_website', 'opt_out_link'],
                'is_active' => true,
                'created_by' => $admin?->id,
            ],
        ];

        foreach ($templates as $tmpl) {
            EmailTemplate::firstOrCreate(['slug' => $tmpl['slug']], $tmpl);
        }
    }
}
