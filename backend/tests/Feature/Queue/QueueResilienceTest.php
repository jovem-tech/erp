<?php

namespace Tests\Feature\Queue;

use App\Jobs\ProcessOrderDocumentSendJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class QueueResilienceTest extends TestCase
{
    public function test_scheduler_has_a_bounded_fallback_for_supervised_queues(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $candidate): bool => $candidate->description === 'queue-supervisor-fallback');

        $this->assertInstanceOf(Event::class, $event);
        $this->assertStringContainsString('queue:work', (string) $event->command);
        $this->assertStringContainsString('--queue=documents,default', (string) $event->command);
        $this->assertStringNotContainsString('--stop-when-empty', (string) $event->command);
        $this->assertStringContainsString('--max-jobs=50', (string) $event->command);
        $this->assertStringContainsString('--max-time=55', (string) $event->command);
        $this->assertStringContainsString('--sleep=1', (string) $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->runInBackground);

        $job = new ProcessOrderDocumentSendJob(1);
        $this->assertGreaterThan(
            $job->timeout,
            (int) config('queue.connections.redis.retry_after'),
            'O retry_after do Redis deve superar o timeout do job para impedir processamento concorrente.'
        );
    }
}
