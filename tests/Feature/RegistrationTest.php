<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected array $valid = [
        'name' => 'Sara Khan', 'email' => 'Sara@Eziline.com', 'phone' => '+92 300 1234567',
        'password' => 'Str0ngPass', 'password_confirmation' => 'Str0ngPass', 'terms' => '1',
    ];

    public function test_register_page_renders_and_login_links_to_it_without_demo_credentials(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create your account')->assertSee('first account becomes');
        $login = $this->get('/login')->assertOk();
        $login->assertSee('Create an account');
        $login->assertDontSee('password123');
        $login->assertDontSee('Quick Login Credentials');
    }

    public function test_first_account_of_an_empty_install_becomes_admin_and_is_logged_in(): void
    {
        $this->post('/register', $this->valid)->assertRedirect(route('dashboard'));
        $u = User::firstOrFail();
        $this->assertSame(['admin', 'sara@eziline.com', true], [$u->role, $u->email, $u->is_active]);
        $this->assertTrue(Hash::check('Str0ngPass', $u->password));
        $this->assertAuthenticatedAs($u);
        $this->assertSame(1, AuditLog::where('action', 'user_registered')->count());
    }

    public function test_later_accounts_get_the_sales_role_and_cannot_reuse_an_email(): void
    {
        User::factory()->create(['role' => 'admin', 'email' => 'owner@eziline.com']);
        $this->post('/register', $this->valid)->assertRedirect(route('dashboard'));
        $this->assertSame('sales', User::where('email', 'sara@eziline.com')->value('role'));
        $this->assertFalse(User::where('email', 'sara@eziline.com')->first()->isAdmin());

        auth()->logout();
        $this->post('/register', $this->valid)->assertSessionHasErrors('email');
        $this->assertSame(2, User::count());
    }

    public function test_weak_password_mismatch_and_missing_terms_are_rejected(): void
    {
        $this->post('/register', ['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'] + $this->valid)->assertSessionHasErrors('password');
        $this->post('/register', ['password_confirmation' => 'Other123'] + $this->valid)->assertSessionHasErrors('password');
        $this->post('/register', ['terms' => null] + $this->valid)->assertSessionHasErrors('terms');
        $this->assertSame(0, User::count());
    }

    public function test_registration_can_be_switched_off_and_logged_in_users_are_sent_to_the_dashboard(): void
    {
        config(['leadpilot.registration.enabled' => false]);
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->valid)->assertNotFound();
        $this->get('/login')->assertDontSee('Create an account');

        config(['leadpilot.registration.enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => 'sales']))->get('/register')->assertRedirect(route('dashboard'));
    }

    public function test_private_sign_up_link_opens_registration_only_with_its_key_and_hides_the_login_link(): void
    {
        config(['leadpilot.registration.key' => 'team-secret-123']);

        $this->get('/login')->assertOk()->assertDontSee('Create an account');
        $this->get('/register')->assertNotFound();
        $this->get('/register?key=wrong')->assertNotFound();
        $this->post('/register', $this->valid)->assertNotFound();
        $this->post('/register', ['key' => 'wrong'] + $this->valid)->assertNotFound();

        $this->get('/register?key=team-secret-123')->assertOk()->assertSee('Create your account')->assertSee('name="key" value="team-secret-123"', false);
        $this->post('/register', ['key' => 'team-secret-123'] + $this->valid)->assertRedirect(route('dashboard'));
        $this->assertSame(1, User::count());
    }
}
