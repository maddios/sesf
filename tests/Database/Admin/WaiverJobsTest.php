<?php

namespace Tests\Database\Admin;

use App\Jobs\Waivers\RegenerateWaiverPdfs;
use App\Jobs\Waivers\SendWaiverReminder;
use App\Jobs\Waivers\SendWaiverRequest;
use App\Jobs\Waivers\ThrottleWaiverMail;
use App\Models\Waiver;
use App\Services\Admin\WaiverRoster;
use App\Services\Waivers\WaiverMailer;
use App\Services\Waivers\WaiverSignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The queued waiver jobs: they really go to the queue, a throttle release never uses up the retry budget, a mail
 * failure is retried, and PDF regeneration runs in batches.
 */
class WaiverJobsTest extends AdminTestCase
{
    use RegistrationFixtures;

    #[Test]
    public function the_waiver_jobs_are_queued_not_run_in_the_request(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new SendWaiverRequest(1, null));
        $this->assertInstanceOf(ShouldQueue::class, new SendWaiverReminder(1, 1));
        $this->assertInstanceOf(ShouldQueue::class, new RegenerateWaiverPdfs(1));
    }

    #[Test]
    public function the_queued_payload_retries_releases_until_a_deadline_and_stops_after_three_failures(): void
    {
        config(['queue.connections.database.table' => 'queue_jobs']);

        foreach ([new SendWaiverRequest(1, null), new SendWaiverReminder(1, 1)] as $job) {
            Queue::connection('database')->push($job);
        }

        $payloads = DB::table('queue_jobs')->pluck('payload')->map(fn (string $json): array => json_decode($json, true));
        $this->assertCount(2, $payloads);
        foreach ($payloads as $payload) {
            $this->assertSame(0, $payload['maxTries']);
            $this->assertSame(3, $payload['maxExceptions']);
            $this->assertGreaterThan(now()->addHour()->getTimestamp(), $payload['retryUntil']);
        }
    }

    #[Test]
    public function a_job_over_the_mail_budget_is_released_without_running(): void
    {
        RateLimiter::clear('admin-waiver-mail');
        for ($i = 0; $i < ThrottleWaiverMail::PER_MINUTE; $i++) {
            RateLimiter::hit('admin-waiver-mail', 60);
        }
        $job = (new SendWaiverRequest(1, null))->withFakeQueueInteractions();

        (new ThrottleWaiverMail)->handle($job, fn () => $this->fail('A throttled job must not run.'));

        $job->assertReleased();
        RateLimiter::clear('admin-waiver-mail');
    }

    #[Test]
    public function a_mail_failure_inside_the_request_job_is_rethrown_so_the_job_retries(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP down'));

        $this->expectException(RuntimeException::class);
        (new SendWaiverRequest($registration->id, null))->handle(app(WaiverMailer::class));
    }

    #[Test]
    public function a_mail_failure_inside_the_reminder_job_is_rethrown_so_the_job_retries(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP down'));

        $this->expectException(RuntimeException::class);
        (new SendWaiverReminder($show->id, $registration->id))->handle(app(WaiverMailer::class));
    }

    #[Test]
    public function the_bulk_mailer_still_counts_a_failure_instead_of_throwing(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP down'));

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 1], app(WaiverMailer::class)->sendRequests([$registration]));
    }

    #[Test]
    public function pdf_regeneration_handles_one_batch_and_queues_the_next(): void
    {
        Queue::fake();
        $show = $this->registrationShow();
        $ids = [];
        for ($i = 0; $i < RegenerateWaiverPdfs::BATCH + 2; $i++) {
            $registration = $this->registrationWithCarAndGuest($show);
            $waiver = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
            DB::table('show_registration')->where('id', $registration->id)->update(['waiver_id' => $waiver->id]);
            $ids[] = (string) $waiver->waiver_id;
        }
        sort($ids);
        $this->mock(WaiverSignService::class)->shouldReceive('pdfPathForAdmin')->times(RegenerateWaiverPdfs::BATCH)->andReturn(null);

        (new RegenerateWaiverPdfs($show->id))->handle(app(WaiverRoster::class), app(WaiverSignService::class));

        $last = $ids[RegenerateWaiverPdfs::BATCH - 1];
        Queue::assertPushed(RegenerateWaiverPdfs::class, fn (RegenerateWaiverPdfs $job): bool => $job->showId === $show->id && $job->afterWaiverId === $last);
    }

    #[Test]
    public function the_last_pdf_batch_queues_nothing_more(): void
    {
        Queue::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $waiver = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
        DB::table('show_registration')->where('id', $registration->id)->update(['waiver_id' => $waiver->id]);
        $this->mock(WaiverSignService::class)->shouldReceive('pdfPathForAdmin')->once()->andReturn(null);

        (new RegenerateWaiverPdfs($show->id))->handle(app(WaiverRoster::class), app(WaiverSignService::class));

        Queue::assertNotPushed(RegenerateWaiverPdfs::class);
    }
}
