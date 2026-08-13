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
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
