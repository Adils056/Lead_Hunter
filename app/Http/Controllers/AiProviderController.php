<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Services\Sales\AiProviderChain;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → AI Models: the ordered list of models (and their keys) LeadPilot uses for drafting and analysis. */
class AiProviderController extends Controller
{
    protected function admin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function index()
    {
        $this->admin();

        return view('settings.ai_providers', [
            'providers' => AiProvider::orderBy('priority')->orderBy('id')->get(),
            'presets' => AiProvider::PRESETS,
            'edit' => request()->integer('edit') ? AiProvider::find(request()->integer('edit')) : null,
        ]);
    }

    public function store(Request $request)
    {
        $this->admin();
        $data = $this->validated($request, true);
        $p = AiProvider::create($data + ['is_active' => filled($data['api_key'] ?? null)]);
        AuditLog::record('ai_provider_added', $p);

        return redirect()->route('settings.ai-providers')->with('success', "{$p->name} added" . ($p->is_active ? ' and switched on.' : '. Add its key to switch it on.'));
    }

    public function update(Request $request, AiProvider $provider)
    {
        $this->admin();
        $data = $this->validated($request, false);
        $key = $data['api_key'] ?? null;
        unset($data['api_key']);
        $provider->fill($data);
        if (filled($key)) {
            $provider->api_key = $key;
            $provider->forceFill(['cooldown_until' => null, 'last_status' => null, 'last_error' => null]);
            if (!$provider->getOriginal('api_key')) {
                $provider->is_active = true; // a row added without a key starts working once it has one
            }
        }
        $provider->save();
        $shared = 0;
        if (filled($key) && $request->boolean('same_key_for_service')) {
            // one Groq / Gemini key serves every model on that service
            foreach (AiProvider::where('id', '<>', $provider->id)->where('kind', $provider->kind)->where('base_url', $provider->base_url)->get() as $other) {
                $other->forceFill(['api_key' => $key, 'cooldown_until' => null, 'last_status' => null, 'last_error' => null,
                    'is_active' => $other->api_key ? $other->is_active : !in_array($other->model, AiProvider::OFF_IN_OWNER_LIST, true)])->save();
                $shared++;
            }
        }
        AuditLog::record('ai_provider_updated', $provider);

        return redirect()->route('settings.ai-providers')->with('success', "{$provider->name} saved." . ($shared ? " Key also set on {$shared} other model(s) of the same service." : ''));
    }

    public function toggle(AiProvider $provider)
    {
        $this->admin();
        if (!$provider->is_active && !$provider->api_key) {
            return back()->with('error', "Add a key for {$provider->name} first (Edit).");
        }
        $provider->update(['is_active' => !$provider->is_active]);

        return back()->with('success', "{$provider->name} " . ($provider->is_active ? 'turned on.' : 'turned off.'));
    }

    public function test(AiProvider $provider, AiProviderChain $chain)
    {
        $this->admin();
        if (!$provider->api_key) {
            return back()->with('error', "{$provider->name} has no key yet.");
        }
        $r = $chain->test($provider);

        return back()->with($r['ok'] ? 'success' : 'error', "{$provider->name} ({$provider->model}): {$r['message']} {$r['ms']} ms.");
    }

    public function destroy(AiProvider $provider)
    {
        $this->admin();
        $provider->delete();
        AuditLog::record('ai_provider_removed', null, ['name' => $provider->name]);

        return back()->with('success', "{$provider->name} removed.");
    }

    /** Adds the owner's recommended list once (rows that already exist with the same model are left alone). */
    public function recommended()
    {
        $this->admin();
        $added = AiProvider::addRecommended();

        return back()->with('success', "{$added} model(s) added. Each stays off until its key is set: Edit one per service and tick \"same key for this service\".");
    }

    protected function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'preset' => [$creating ? 'required' : 'nullable', Rule::in(array_keys(AiProvider::PRESETS))],
            'name' => ['nullable', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'base_url' => ['nullable', 'url:https', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'priority' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $preset = AiProvider::PRESETS[$data['preset'] ?? ''] ?? null;
        if ($preset) {
            $data['kind'] = $preset['kind'];
            $data['base_url'] = $data['base_url'] ?: $preset['base_url'];
        }
        if ($creating && empty($data['base_url'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['base_url' => 'Give the API base URL for this service.']);
        }
        if (empty($data['name'])) {
            $data['name'] = $creating ? ($preset['label'] ?? 'AI') . ' / ' . $data['model'] : null;
        }
        if (!$creating) {
            $data = array_filter($data, fn ($v, $k) => $v !== null || in_array($k, ['daily_limit'], true), ARRAY_FILTER_USE_BOTH); // empty = keep
        }
        $data['api_key'] = isset($data['api_key']) ? trim($data['api_key']) : null;
        unset($data['preset']);

        return $data;
    }
}
