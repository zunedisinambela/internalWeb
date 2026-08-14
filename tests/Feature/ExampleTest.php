<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * RefreshDatabase is required now that every `web` request passes through
     * RecordVisit and writes to visits_monitoring. The middleware survives a
     * missing table on its own, but running without migrations would only
     * assert that the fallback works, not that the page does.
     */
    use RefreshDatabase;

    /**
     * The panel owns the root path, so `/` is the dashboard and a guest is
     * redirected to sign in. The login screen is the only page this app now
     * serves to an anonymous visitor, and it is what "the application boots
     * and renders" is asserted against.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect('/login');

        $this->get('/login')->assertStatus(200);
    }
}
