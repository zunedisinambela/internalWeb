<?php

namespace Tests\Unit;

use App\Monitoring\PageViewsOnly;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * PageViewsOnly decides what lands in visits_monitoring. It is driven entirely
 * by the request, so it is cheaper to test directly than through the panel.
 */
class PageViewsOnlyTest extends TestCase
{
    private PageViewsOnly $condition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->condition = new PageViewsOnly;
    }

    public function test_a_plain_get_is_a_page_view(): void
    {
        $this->assertTrue($this->condition->shouldMonitor(Request::create('/', 'GET')));
    }

    /**
     * Writes are actions, not page views, and the ones worth auditing are
     * already covered: logins by authentication monitoring, model changes by
     * activitylog.
     */
    public function test_writes_are_not_page_views(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertFalse(
                $this->condition->shouldMonitor(Request::create('/', $method)),
                $method.' should not be recorded as a visit.',
            );
        }
    }

    /**
     * Matching on the header rather than the path is deliberate: Livewire's URL
     * prefix is obfuscated and changes with the app key, so except_pages cannot
     * catch it reliably.
     */
    public function test_livewire_requests_are_rejected_by_header(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Livewire', 'true');

        $this->assertFalse($this->condition->shouldMonitor($request));
    }

    public function test_json_requests_are_rejected(): void
    {
        $request = Request::create('/log-viewer/api/files', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->assertFalse($this->condition->shouldMonitor($request));
    }

    public function test_prefetches_are_rejected(): void
    {
        foreach (['Sec-Purpose', 'Purpose'] as $header) {
            $request = Request::create('/', 'GET');
            $request->headers->set($header, 'prefetch');

            $this->assertFalse(
                $this->condition->shouldMonitor($request),
                $header.': prefetch should not be recorded as a visit.',
            );
        }
    }
}
