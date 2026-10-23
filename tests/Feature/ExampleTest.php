<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_url_redirects_to_dashboard(): void
    {
        $response = $this->get('/');
        $response->assertStatus(302);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertStatus(302);
        $response->assertRedirect(route('login'));
    }
}
