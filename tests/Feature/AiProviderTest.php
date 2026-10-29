<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use App\Services\Sales\AiProviderChain;
use App\Services\Sales\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Settings → AI Models (30 Oct 2026): the owner's ordered list of free-tier models with daily limits. */
class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_recommended_list_is_added_switched_off_and_one_key_serves_a_whole_service(): void
    {
        $this->actingAs($this->admin)->post(route('settings.ai-providers.recommended'))->assertRedirect();
        $this->assertSame(14, AiProvider::count());
        $this->assertSame(0, AiProvider::where('is_active', true)->count(), 'no key yet: nothing is called');
        $this->actingAs($this->admin)->post(route('settings.ai-providers.recommended'));
        $this->assertSame(14, AiProvider::count(), 'adding the list twice does not duplicate it');

        $groq = AiProvider::where('model', 'openai/gpt-oss-120b')->first();
        $this->actingAs($this->admin)->put(route('settings.ai-providers.update', $groq), [
            'model' => $groq->model, 'priority' => 10, 'daily_limit' => 1000, 'api_key' => 'gsk_secret_3gMj', 'same_key_for_service' => 1,
        ])->assertRedirect();

        $groqRows = AiProvider::where('base_url', 'https://api.groq.com/openai/v1')->get();
        $this->assertCount(4, $groqRows);
        $this->assertTrue($groqRows->every(fn ($p) => $p->api_key === 'gsk_secret_3gMj'));
        $this->assertSame(['openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'qwen/qwen3.8-27b'], $groqRows->where('is_active', true)->sortBy('priority')->pluck('model')->values()->all(),
            'qwen3.6 stays off, as in the owner\'s list');
        $this->assertSame(0, AiProvider::where('kind', 'gemini')->where('is_active', true)->count(), 'other services untouched');
        $this->assertStringNotContainsString('gsk_secret_3gMj', (string) \DB::table('ai_providers')->where('id', $groq->id)->value('api_key'), 'stored encrypted');

        $page = $this->actingAs($this->admin)->get(route('settings.ai-providers'))->assertOk();
        $page->assertSee('••••••3gMj')->assertDontSee('gsk_secret_3gMj')->assertSee('Needs key')->assertSee('0 / 1000');
    }

    public function test_the_chain_skips_rate_limited_and_exhausted_models_and_counts_each_call(): void
    {
        $groq = AiProvider::create(['priority' => 10, 'name' => 'Groq', 'kind' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1', 'model' => 'openai/gpt-oss-120b', 'api_key' => 'k1', 'daily_limit' => 1000, 'is_active' => true]);
        $full = AiProvider::create(['priority' => 15, 'name' => 'Cerebras', 'kind' => 'openai', 'base_url' => 'https://api.cerebras.ai/v1', 'model' => 'gpt-oss-120b', 'api_key' => 'k2', 'daily_limit' => 2, 'is_active' => true]);
        $full->forceFill(['used_today' => 2, 'used_on' => AiProvider::today()])->save();
        $gemini = AiProvider::create(['priority' => 20, 'name' => 'Gemini', 'kind' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-3.6-flash', 'api_key' => 'k3', 'daily_limit' => 20, 'is_active' => true]);
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429),
            'api.cerebras.ai/*' => Http::response(['choices' => [['message' => ['content' => '{"subject":"never"}']]]], 200),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => "```json\n{\"subject\":\"Hello\"}\n```"]]]]]], 200),
        ]);

        $r = app(AiProviderChain::class)->json('sys', 'user', ['type' => 'object']);

        $this->assertSame(['data' => ['subject' => 'Hello'], 'provider' => 'Gemini'], $r);
        $this->assertSame('cooling', $groq->fresh()->state(), 'rate limited: rests 10 minutes');
        $this->assertSame(2, $full->fresh()->usedToday(), 'at its daily limit: not called');
        $this->assertSame(1, $gemini->fresh()->usedToday());
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'cerebras'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'gemini-3.6-flash:generateContent') && $req->hasHeader('x-goog-api-key', 'k3'));

        $this->assertSame('Gemini', app(LlmClient::class)->json('sys', 'user', ['type' => 'object'])['provider'], 'every AI feature uses the list first');

        $this->travel(11)->minutes();
        $this->assertSame('ready', $groq->fresh()->state(), 'back after the rest');
    }

    public function test_a_bad_key_shows_as_error_and_test_button_reports_it(): void
    {
        $p = AiProvider::create(['priority' => 10, 'name' => 'Mistral', 'kind' => 'openai', 'base_url' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-2603', 'api_key' => 'bad', 'daily_limit' => 2000, 'is_active' => true]);
        Http::fake(['api.mistral.ai/*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->actingAs($this->admin)->post(route('settings.ai-providers.test', $p))->assertSessionHas('error');
        $this->assertSame('error', $p->fresh()->state());
        $this->actingAs($this->admin)->get(route('settings.ai-providers'))->assertSee('Error')->assertSee('HTTP 401');

        $this->actingAs($this->admin)->post(route('settings.ai-providers.toggle', $p));
        $this->assertSame('off', $p->fresh()->state());
        $this->actingAs($this->admin)->delete(route('settings.ai-providers.destroy', $p))->assertRedirect();
        $this->assertSame(0, AiProvider::count());

        $sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $this->actingAs($sales)->get(route('settings.ai-providers'))->assertForbidden();
    }

    public function test_keys_can_be_set_from_the_terminal_with_hidden_input_matching_the_owners_list(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"ok": true}']]]]]], 200),
            '*' => Http::response(['choices' => [['message' => ['content' => '{"ok": true}']]]], 200),
        ]);
        $this->artisan('leadpilot:ai-keys')
            ->expectsQuestion('Groq key (4 model(s))', 'gsk_groq')
            ->expectsConfirmation('Use this Groq key for all 4 models?', 'yes')
            ->expectsQuestion('Google Gemini key (6 model(s))', 'g_main')
            ->expectsConfirmation('Use this Google Gemini key for all 6 models?', 'no')
            ->expectsQuestion('Key for gemini-3.6-flash (Enter = the same Google Gemini key)', '')
            ->expectsQuestion('Key for gemma-4-31b-it (Enter = the same Google Gemini key)', 'g_gemma31')
            ->expectsQuestion('Key for gemma-4-26b-a4b-it (Enter = the same Google Gemini key)', 'g_gemma26')
            ->expectsQuestion('Key for gemini-3.5-flash (Enter = the same Google Gemini key)', '')
            ->expectsQuestion('Key for gemini-3.1-flash-lite (Enter = the same Google Gemini key)', '')
            ->expectsQuestion('Key for gemini-3.5-flash-lite (Enter = the same Google Gemini key)', 'g_lite2')
            ->expectsQuestion('OpenRouter key (1 model(s))', '')
            ->expectsQuestion('Cerebras key (1 model(s))', 'csk')
            ->expectsQuestion('Mistral key (1 model(s))', '')
            ->expectsQuestion('DeepSeek key (1 model(s))', '')
            ->assertSuccessful();

        $key = fn (string $model) => AiProvider::where('model', $model)->first();
        $this->assertSame(['gsk_groq', true], [$key('openai/gpt-oss-120b')->api_key, $key('openai/gpt-oss-120b')->is_active]);
        $this->assertSame(['gsk_groq', false], [$key('qwen/qwen3.6-27b')->api_key, $key('qwen/qwen3.6-27b')->is_active], 'off in the owner\'s list');
        $this->assertSame(['g_main', 'g_gemma31', 'g_gemma26', 'g_lite2'], [$key('gemini-3.6-flash')->api_key, $key('gemma-4-31b-it')->api_key, $key('gemma-4-26b-a4b-it')->api_key, $key('gemini-3.5-flash-lite')->api_key]);
        $this->assertFalse($key('gemma-4-31b-it')->is_active);
        $this->assertSame(['csk', false], [$key('gpt-oss-120b')->api_key, $key('gpt-oss-120b')->is_active], 'Cerebras is off in the owner\'s list');
        $this->assertNull($key('mistral-small-2603')->api_key, 'skipped');
        $this->assertSame(['Ready', 'Turned off', 'Needs key'], [
            $key('openai/gpt-oss-120b')->state() === 'ready' ? 'Ready' : 'x', $key('gpt-oss-120b')->state() === 'off' ? 'Turned off' : 'x', $key('mistral-small-2603')->state() === 'no_key' ? 'Needs key' : 'x']);
    }
}
