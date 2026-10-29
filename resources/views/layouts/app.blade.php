<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#4f46e5">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="LeadPilot">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <script>if ('serviceWorker' in navigator) { window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {})); }</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') — LeadPilot AI</title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3.3 & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>

    <style>
        :root {
            --lp-navy: #0f172a;
            --lp-navy-light: #1e293b;
            --lp-navy-border: #334155;
            --lp-primary: #4f46e5;
            --lp-primary-hover: #4338ca;
            --lp-accent: #06b6d4;
            --lp-bg: #f8fafc;
            --lp-card-bg: #ffffff;
            --lp-border: #e2e8f0;
            --lp-text-dark: #0f172a;
            --lp-text-muted: #64748b;
        }

        [x-cloak] { display: none !important; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--lp-bg);
            color: var(--lp-text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* Sidebar Styling */
        #sidebar {
            width: 270px;
            background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
            color: #f8fafc;
            min-height: 100vh;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 1040;
            transition: all 0.3s ease;
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.12);
            overflow-y: auto;
        }

        .sidebar-brand {
            padding: 1.5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.4);
        }

        .sidebar-brand .logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.25rem;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
        }

        .sidebar-section-title {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            font-weight: 700;
            padding: 1rem 1.25rem 0.4rem;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.7rem 1.25rem;
            color: #cbd5e1;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            border-radius: 8px;
            margin: 0.15rem 0.75rem;
            transition: all 0.2s ease;
        }

        .nav-link-custom i {
            font-size: 1.15rem;
            color: #94a3b8;
            transition: all 0.2s ease;
        }

        .nav-link-custom:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
        }

        .nav-link-custom:hover i {
            color: var(--lp-accent);
            transform: translateX(2px);
        }

        .nav-link-custom.active {
            color: #ffffff;
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.85) 0%, rgba(67, 56, 202, 0.85) 100%);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
            font-weight: 600;
        }

        .nav-link-custom.active i {
            color: #ffffff;
        }

        /* Top Navbar & Main Content */
        #main-content {
            margin-left: 270px;
            min-width: 0; /* a wide table or long URL stays inside the content area instead of widening the page */
            overflow-x: clip; /* ...so the page never scrolls sideways under the fixed sidebar (SQA bug 30); clip keeps the sticky navbar working */
            flex: 1;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .top-navbar {
            height: 70px;
            background: #ffffff;
            border-bottom: 1px solid var(--lp-border);
            padding: 0 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1020;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.02);
        }

        .content-body {
            padding: 1.75rem;
            flex: 1;
            min-width: 0;
        }

        /* Cards & Metric Cards */
        .card-lp {
            background: var(--lp-card-bg);
            border: 1px solid var(--lp-border);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card-lp:hover {
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        .metric-card {
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid var(--lp-border);
            padding: 1.25rem 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .metric-card .icon-box {
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }

        .metric-card .metric-val {
            font-size: 1.85rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #0f172a;
        }

        .metric-card .metric-label {
            font-size: 0.825rem;
            color: var(--lp-text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* Badges */
        .badge-lp {
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .badge-score-high {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .badge-score-medium {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fef08a;
        }

        .badge-score-low {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        /* Buttons */
        .btn-primary-lp {
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
            transition: all 0.2s ease;
        }

        .btn-primary-lp:hover {
            background: linear-gradient(135deg, #4338ca 0%, #3730a3 100%);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
        }

        /* Data Tables */
        .table-lp {
            margin-bottom: 0;
        }

        .table-lp thead th {
            background: #f8fafc;
            color: #475569;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            border-bottom: 1px solid var(--lp-border);
            padding: 0.85rem 1rem;
        }

        .table-lp tbody td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.875rem;
        }

        .table-lp tbody tr:hover {
            background-color: #f8fafc;
        }

        /* Score / status pills and button labels never break inside ("94 pts", "Edi|t", "New|ICP") */
        .badge-lp, .badge, .content-body .btn { white-space: nowrap; }

        /* Page headers: when title and action buttons do not fit on one line, the buttons move below */
        .content-body .d-flex.justify-content-between { flex-wrap: wrap; gap: 0.75rem; }
        .content-body .d-flex.justify-content-between:has(> .icon-box) { flex-wrap: nowrap; } /* KPI cards keep their icon on the right */

        /* Long e-mails, URLs and names inside flex rows wrap instead of widening the page */
        .content-body .d-flex > span, .content-body .d-flex > a:not(.btn), .content-body dd, .content-body .card-lp p {
            min-width: 0;
            overflow-wrap: break-word; /* breaks only a word wider than its box, never "Qualifi|ed" */
        }
        .content-body pre { white-space: pre-wrap; overflow-wrap: anywhere; }
        .content-body .font-monospace, .content-body code { overflow-wrap: anywhere; }
        .content-body img { max-width: 100%; height: auto; }

        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 1035;
        }

        @media (max-width: 991.98px) {
            #sidebar {
                transform: translateX(-100%);
                box-shadow: none; /* the hidden drawer's shadow otherwise shows on the left edge */
            }
            #sidebar.active {
                transform: translateX(0);
                box-shadow: 4px 0 24px rgba(0, 0, 0, 0.25);
            }
            #main-content {
                margin-left: 0;
                min-width: 0;
            }
            .top-navbar { padding: 0 1rem; }
            .content-body { padding: 1rem; }
            /* page headers and toolbars: let title, filters and buttons stack instead of squeezing */
            .content-body .btn-group,
            .content-body .d-flex:has(> .badge, > .btn, > form) { flex-wrap: wrap; gap: 0.5rem; }
            .content-body .btn-group > .btn { flex: 0 0 auto; }
            /* wide tables keep readable columns and scroll sideways instead of squeezing into one word per line */
            .content-body .table-responsive > table:has(th:nth-child(4)) { min-width: 720px; }
        }
    </style>
</head>
<body x-data="{ mobileNavOpen: false }">

    <!-- Sidebar Navigation -->
    <div class="sidebar-backdrop d-lg-none" x-cloak x-show="mobileNavOpen" x-transition.opacity @click="mobileNavOpen = false"></div>
    <aside id="sidebar" :class="{ 'active': mobileNavOpen }" @keydown.escape.window="mobileNavOpen = false">
        <div class="sidebar-brand">
            <div class="logo-icon">
                <i class="bi bi-radar"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-white fs-6">LeadPilot AI</h5>
                <span class="badge bg-primary-subtle text-primary fw-semibold" style="font-size: 0.65rem;">B2B Sales Intel</span>
            </div>
        </div>

        <div class="py-2">
            <div class="sidebar-section-title">Core Modules</div>
            <a href="{{ route('dashboard') }}" class="nav-link-custom {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Executive Dashboard</span>
            </a>
            <a href="{{ route('companies.index') }}" class="nav-link-custom {{ request()->routeIs('companies.*') ? 'active' : '' }}">
                <i class="bi bi-buildings"></i>
                <span>Company Intel</span>
            </a>
            @php
                $u = auth()->user();
                $pendingApprovals = $u ? \App\Models\ApprovalRequest::open()
                    ->when(!$u->isAdmin(), fn ($q) => $q->whereIn('type', \App\Services\Autopilot\ApprovalService::SALES_TYPES)->where(fn ($w) => $w->whereNull('assigned_user_id')->orWhere('assigned_user_id', $u->id)))
                    ->count() : 0;
            @endphp
            @if($u?->isAdmin())
            <a href="{{ route('autopilot.index') }}" class="nav-link-custom {{ request()->routeIs('autopilot.index') ? 'active' : '' }}">
                <i class="bi bi-rocket-takeoff"></i>
                <span>Autopilot</span>
            </a>
            @endif
            <a href="{{ route('approvals.index') }}" class="nav-link-custom {{ request()->routeIs('approvals.*') ? 'active' : '' }}">
                <i class="bi bi-check2-square"></i>
                <span>Approvals @if($pendingApprovals)<span class="badge bg-warning text-dark ms-1">{{ $pendingApprovals }}</span>@endif</span>
            </a>
            @if($u?->isAdmin())
            <a href="{{ route('autopilot.performance') }}" class="nav-link-custom {{ request()->routeIs('autopilot.performance') ? 'active' : '' }}">
                <i class="bi bi-bar-chart-line"></i>
                <span>Performance</span>
            </a>
            @endif
            <a href="{{ route('hunting.index') }}" class="nav-link-custom {{ request()->routeIs('hunting.*') ? 'active' : '' }}">
                <i class="bi bi-crosshair"></i>
                <span>Lead Hunter (ICP)</span>
            </a>
            <a href="{{ route('leads.index') }}" class="nav-link-custom {{ (request()->routeIs('leads.*') && !request()->routeIs('leads.win-ladder*')) || request()->routeIs('assistant.*') ? 'active' : '' }}">
                <i class="bi bi-person-lines-fill"></i>
                <span>Lead Intelligence</span>
            </a>
            <a href="{{ route('leads.win-ladder') }}" class="nav-link-custom {{ request()->routeIs('leads.win-ladder*') ? 'active' : '' }}">
                <i class="bi bi-trophy"></i>
                <span>Win Ladder</span>
            </a>
            <a href="{{ route('buying-signals.index') }}" class="nav-link-custom {{ request()->routeIs('buying-signals.*') ? 'active' : '' }}">
                <i class="bi bi-broadcast-pin"></i>
                <span>Buying Signals</span>
            </a>

            <div class="sidebar-section-title">CRM & Outreach</div>
            <a href="{{ route('crm.pipeline') }}" class="nav-link-custom {{ request()->routeIs('crm.pipeline') ? 'active' : '' }}">
                <i class="bi bi-kanban-fill"></i>
                <span>Sales Pipeline</span>
            </a>
            <a href="{{ route('outbox.index') }}" class="nav-link-custom {{ request()->routeIs('outbox.*') ? 'active' : '' }}">
                <i class="bi bi-envelope-paper-fill"></i>
                <span>Sent &amp; Replies</span>
            </a>
            <a href="{{ route('campaigns.index') }}" class="nav-link-custom {{ request()->routeIs('campaigns.*') ? 'active' : '' }}">
                <i class="bi bi-send-check-fill"></i>
                <span>Campaign Automation</span>
            </a>
            <a href="{{ route('templates.index') }}" class="nav-link-custom {{ request()->routeIs('templates.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-text"></i>
                <span>Email Templates</span>
            </a>
            <a href="{{ route('whatsapp-templates.index') }}" class="nav-link-custom {{ request()->routeIs('whatsapp-templates.*') ? 'active' : '' }}">
                <i class="bi bi-whatsapp"></i>
                <span>WhatsApp Templates</span>
            </a>
            <a href="{{ route('analytics.index') }}" class="nav-link-custom {{ request()->routeIs('analytics.*') ? 'active' : '' }}">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Analytics & Reports</span>
            </a>

            @if(Auth::user()?->isAdmin())
            <div class="sidebar-section-title">Administration</div>
            <a href="{{ route('settings.outreach-automation') }}" class="nav-link-custom {{ request()->routeIs('settings.outreach-automation*') ? 'active' : '' }}">
                <i class="bi bi-robot"></i>
                <span>Outreach Engine</span>
            </a>
            <a href="{{ route('settings.integrations') }}" class="nav-link-custom {{ request()->routeIs('settings.integrations') ? 'active' : '' }}">
                <i class="bi bi-sliders"></i>
                <span>Integrations & API</span>
            </a>
            <a href="{{ route('settings.ai-providers') }}" class="nav-link-custom {{ request()->routeIs('settings.ai-providers*') ? 'active' : '' }}">
                <i class="bi bi-cpu"></i>
                <span>AI Models</span>
            </a>
            <a href="{{ route('autopilot.policy') }}" class="nav-link-custom {{ request()->routeIs('autopilot.policy') ? 'active' : '' }}">
                <i class="bi bi-shield-check"></i>
                <span>Autonomy &amp; Budgets</span>
            </a>
            <a href="{{ route('autopilot.setup') }}" class="nav-link-custom {{ request()->routeIs('autopilot.setup') ? 'active' : '' }}">
                <i class="bi bi-heart-pulse"></i>
                <span>Setup &amp; Health</span>
            </a>
            <a href="{{ route('settings.scoring') }}" class="nav-link-custom {{ request()->routeIs('settings.scoring*') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i>
                <span>Lead Scoring</span>
            </a>
            @endif

            <div class="sidebar-section-title">Account</div>
            <a href="{{ route('profile') }}" class="nav-link-custom {{ request()->routeIs('profile') ? 'active' : '' }}">
                <i class="bi bi-person-circle"></i>
                <span>My Profile</span>
            </a>
            <form action="{{ route('logout') }}" method="POST" class="px-2 mt-2">
                @csrf
                <button type="submit" class="nav-link-custom text-danger bg-transparent border-0 w-100 text-start">
                    <i class="bi bi-box-arrow-right text-danger"></i>
                    <span>Sign Out</span>
                </button>
            </form>
        </div>
    </aside>

    <!-- Main Workspace -->
    <div id="main-content">
        <!-- Top Navigation -->
        <header class="top-navbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-sm btn-outline-secondary d-lg-none" @click="mobileNavOpen = !mobileNavOpen">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div class="d-none d-md-flex align-items-center text-muted" style="font-size: 0.85rem;">
                    <i class="bi bi-shield-check text-success me-1"></i>
                    <span>Production Safe Mode • Suppression Enabled</span>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <a href="{{ route('leads.create') }}" class="btn btn-sm btn-primary-lp d-none d-sm-inline-flex align-items-center gap-1">
                    <i class="bi bi-plus-lg"></i>
                    <span>Add Prospect</span>
                </a>

                @php $unread = auth()->user()?->unreadNotifications()->count() ?? 0; @endphp
                <a href="{{ route('notifications.index') }}" class="btn btn-sm btn-light border position-relative" title="Notifications">
                    <i class="bi bi-bell"></i>@if($unread)<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ $unread }}</span>@endif
                </a>
                <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width: 28px; height: 28px; font-size: 0.75rem;">
                            {{ strtoupper(substr(Auth::user()->name ?? 'U', 0, 1)) }}
                        </div>
                        <span class="d-none d-sm-inline fw-semibold" style="font-size: 0.85rem;">{{ Auth::user()->name ?? 'User' }}</span>
                        <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.65rem;">{{ strtoupper(Auth::user()->role ?? 'sales') }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="{{ route('profile') }}"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        @if(Auth::user()?->isAdmin())
                        <li><a class="dropdown-item" href="{{ route('settings.integrations') }}"><i class="bi bi-gear me-2"></i>API Integrations</a></li>
                        @endif
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="{{ route('logout') }}" method="POST">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Log Out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Flash Messages -->
        <div class="px-4 pt-3">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 border-0 shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill fs-5 text-success"></i>
                    <div>{{ session('success') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 border-0 shadow-sm" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-5 text-danger"></i>
                    <div>{{ session('error') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if(session('warning'))
                <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-2 border-0 shadow-sm" role="alert">
                    <i class="bi bi-exclamation-circle-fill fs-5 text-warning"></i>
                    <div>{{ session('warning') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if(session('info'))
                <div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2 border-0 shadow-sm" role="alert">
                    <i class="bi bi-info-circle-fill fs-5 text-info"></i>
                    <div>{{ session('info') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
        </div>

        <!-- Dynamic Content Body -->
        <main class="content-body">
            @yield('content')
        </main>

        <!-- Footer -->
        <footer class="py-3 px-4 bg-white border-top text-muted d-flex justify-content-between align-items-center" style="font-size: 0.8rem;">
            <div>
                <strong>LeadPilot AI</strong> &copy; {{ date('Y') }} — B2B Sales Intelligence & Outreach Automation Platform
            </div>
            <div class="d-none d-md-block text-secondary">
                Agency Edition
            </div>
        </footer>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Every data table scrolls inside its card on small screens instead of widening the whole page.
        document.querySelectorAll('.content-body table').forEach(function (t) {
            if (t.closest('.table-responsive')) return;
            var w = document.createElement('div');
            w.className = 'table-responsive';
            t.parentNode.insertBefore(w, t);
            w.appendChild(t);
        });
    </script>
    @stack('scripts')
</body>
</html>
