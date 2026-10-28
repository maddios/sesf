<?php

namespace Tests\Database\Judging;

use App\Models\Judging\Answer;
use App\Models\ShowRegistrationCar;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/** Standalone judging page tests: one JudgingWorld per test, Vite disabled. */
abstract class JudgingTestCase extends TestCase
{
    use LoadsLegacySchema;

    protected JudgingWorld $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->world = JudgingWorld::create();
    }

    /** Signs in as $user; the session is flushed first so one test can switch users. */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    protected function submit(array $data): TestResponse
    {
        return $this->postJson('/judgingSubmit/'.$this->world->token(), ['data' => $data]);
    }

    /**
     * Stores one answer per given score, for q1, q2, q3 in order.
     *
     * @param  list<string|null>  $scores
     */
    protected function score(ShowRegistrationCar $entry, array $scores): void
    {
        foreach ([$this->world->q1, $this->world->q2, $this->world->q3] as $index => $question) {
            if (array_key_exists($index, $scores)) {
                Answer::factory()->create([
                    'question_id' => $question->id,
                    'show_registration_car_id' => $entry->id,
                    'user_id' => $this->world->judge->id,
                    'data' => $scores[$index],
                ]);
            }
        }
    }
}
