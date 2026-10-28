<?php

namespace Tests\Database\Admin;

use App\Enums\JudgingType;
use App\Mail\JudgingResultMail;
use App\Models\CarShow;
use App\Models\CarShowJudge;
use App\Models\Judging\Answer;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Database\Judging\JudgingWorld;

final class JudgingAdminTest extends AdminTestCase
{
    private JudgingWorld $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->world = JudgingWorld::create();
    }

    private function as(string $who): void
    {
        $user = $this->world->actor($who);
        if ($user !== null) {
            $this->actingAs($user);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, int>}>
     */
    public static function routes(): array
    {
        $judgeRoute = ['anonymous' => 401, 'member' => 403, 'owner' => 403, 'other judge' => 403, 'assigned judge' => 200, 'admin' => 200];
        $adminRoute = ['assigned judge' => 403, 'admin' => 200] + $judgeRoute;

        return [
            'entries' => ['GET', 'entries', $judgeRoute],
            'unjudged' => ['GET', 'unjudged', $judgeRoute],
            'types' => ['GET', 'types?body_id=1', $judgeRoute],
            'judges' => ['GET', 'judges', $adminRoute],
            'eligible judges' => ['GET', 'judges/eligible', $adminRoute],
            'release' => ['PUT', 'release', $adminRoute],
        ];
    }

    /**
     * @param  array<string, int>  $expected
     */
    #[DataProvider('routes')]
    public function test_authorization_matrix(string $method, string $path, array $expected): void
    {
        foreach ($expected as $who => $status) {
            $this->flushSession();
            app('auth')->forgetGuards();
            $this->as($who);

            $this->json($method, '/admin-api/judging/shows/'.$this->world->show->id.'/'.$path, ['released' => true], self::JSON)
                ->assertStatus($status);
        }
    }

    /** Judging admin routes allowed to run without judge_for_show; each scopes its own results. */
    private const SHOWLESS_ROUTES = ['admin-api.judging.shows'];

    public function test_every_judging_admin_route_requires_the_show_scope_unless_opted_out(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('judge_or_admin', $route->gatherMiddleware(), true));

        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $name = (string) $route->getName();
            $scoped = in_array('judge_for_show', $route->gatherMiddleware(), true)
                && ! in_array('judge_for_show', $route->excludedMiddleware(), true);

            if (in_array($name, self::SHOWLESS_ROUTES, true)) {
                $this->assertFalse($scoped, $name);

                continue;
            }

            $this->assertTrue($scoped, $name.' must be scoped to a show');
            $this->assertContains('show', $route->parameterNames(), $name.' must take a {show} route parameter');
        }
    }

    public function test_shows_lists_only_assigned_shows_for_judges(): void
    {
        $this->actingAs($this->world->judge);
        $ids = array_column((array) $this->getJson('/admin-api/judging/shows')->assertOk()->json('data'), 'id');
        $this->assertSame([$this->world->show->id], $ids);

        $this->actingAs($this->world->admin);
        $this->assertContains($this->world->show->id, array_column((array) $this->getJson('/admin-api/judging/shows')->json('data'), 'id'));
    }

    public function test_show_id_comes_from_the_route_not_the_query_or_path(): void
    {
        $other = CarShow::factory()->create();
        $this->actingAs($this->world->judge);

        $this->getJson('/admin-api/judging/shows/'.$other->id.'/entries?show_id='.$this->world->show->id)->assertForbidden();
        $this->postJson('/admin-api/judging/shows/'.$other->id.'/entries', ['show_id' => $this->world->show->id, 'registration_id' => 1, 'car_id' => 1, 'judging_type_id' => 1])->assertForbidden();
    }

    public function test_entries_list_scores_and_links(): void
    {
        Answer::factory()->create(['question_id' => $this->world->q1->id, 'show_registration_car_id' => $this->world->entry->id, 'user_id' => $this->world->judge->id, 'data' => '7.5']);
        $this->actingAs($this->world->judge);

        $row = $this->getJson('/admin-api/judging/shows/'.$this->world->show->id.'/entries')->assertOk()->json('data.0');

        $this->assertSame($this->world->entry->id, $row['id']);
        $this->assertSame('7.5', $row['score']);
        $this->assertSame([3, 1], [$row['num_questions'], $row['num_answered']]);
        $this->assertStringEndsWith('/judging/'.$this->world->token(), $row['judging_url']);
        $this->assertSame('/judging_qr_code/'.$this->world->token(), $row['qr_url']);

        $this->getJson('/admin-api/judging/shows/'.$this->world->show->id.'/entries?judging_type_id='.JudgingType::Concours->value)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/admin-api/judging/shows/'.$this->world->show->id.'/entries?judging_type_id=x')->assertStatus(422);
    }

    public function test_assigning_a_type_is_scoped_to_the_show_and_its_offered_types(): void
    {
        $unjudged = JudgingWorld::entry($this->world->show, User::factory()->create(), null, (int) $this->world->entry->car->body_id);
        $elsewhere = JudgingWorld::entry(CarShow::factory()->create(), User::factory()->create(), null);
        $url = '/admin-api/judging/shows/'.$this->world->show->id.'/entries';
        $this->actingAs($this->world->judge);

        $this->assertContains($unjudged->registration_id, array_column((array) $this->getJson('/admin-api/judging/shows/'.$this->world->show->id.'/unjudged')->json('data'), 'registration_id'));
        $this->getJson('/admin-api/judging/shows/'.$this->world->show->id.'/types?body_id='.$unjudged->car?->body_id)
            ->assertJsonPath('data.0.id', JudgingType::Clean->value);

        $this->postJson($url, ['registration_id' => $elsewhere->registration_id, 'car_id' => $elsewhere->car_id, 'judging_type_id' => 1])->assertNotFound();
        $this->postJson($url, ['registration_id' => $unjudged->registration_id, 'car_id' => $unjudged->car_id, 'judging_type_id' => JudgingType::Concours->value])
            ->assertStatus(422)->assertJsonValidationErrors('judging_type_id');
        $this->postJson($url, ['registration_id' => $unjudged->registration_id, 'car_id' => $unjudged->car_id])->assertStatus(422);

        $this->postJson($url, ['registration_id' => $unjudged->registration_id, 'car_id' => $unjudged->car_id, 'judging_type_id' => JudgingType::Clean->value])->assertCreated();
        $this->assertSame(JudgingType::Clean, $unjudged->fresh()?->judging_type_id);

        $this->deleteJson($url.'/'.$elsewhere->id)->assertNotFound();
        $this->deleteJson($url.'/'.$unjudged->id)->assertNoContent();
        $this->assertNull($unjudged->fresh()->judging_type_id);

        $actions = array_column($this->auditEntries(), 'action');
        $this->assertContains('judging.entry.assign', $actions);
        $this->assertContains('judging.entry.remove', $actions);
    }

    public function test_admin_assigns_and_removes_judge_accounts_only(): void
    {
        $candidate = User::factory()->judge()->create();
        $url = '/admin-api/judging/shows/'.$this->world->show->id.'/judges';
        $this->actingAs($this->world->admin);

        $this->assertContains($candidate->id, array_column((array) $this->getJson($url.'/eligible')->json('data'), 'user_id'));
        $this->postJson($url, ['user_id' => $this->world->member->id])->assertStatus(422);
        $this->postJson($url, ['user_id' => $candidate->id])->assertCreated();
        $this->postJson($url, ['user_id' => $candidate->id])->assertCreated();
        $this->assertSame(1, CarShowJudge::query()->assignedTo($candidate->id, $this->world->show->id)->count());
        $this->assertContains($candidate->id, array_column((array) $this->getJson($url)->json('data'), 'user_id'));

        $this->deleteJson($url.'/'.$candidate->id)->assertNoContent();
        $this->deleteJson($url.'/'.$candidate->id)->assertNotFound();
        $this->assertFalse(CarShowJudge::query()->assignedTo($candidate->id, $this->world->show->id)->exists());
    }

    public function test_release_and_results_mail(): void
    {
        Mail::fake();
        Answer::factory()->create(['question_id' => $this->world->q1->id, 'show_registration_car_id' => $this->world->entry->id, 'user_id' => $this->world->judge->id]);
        JudgingWorld::entry($this->world->show, User::factory()->create(), JudgingType::Clean);
        $base = '/admin-api/judging/shows/'.$this->world->show->id;
        $this->actingAs($this->world->admin);

        $this->postJson($base.'/results-mail')->assertStatus(409);
        Mail::assertNothingQueued();

        $this->putJson($base.'/release', ['released' => true])->assertOk()->assertJsonPath('data.is_judging_released', true);
        $this->postJson($base.'/results-mail')->assertOk()->assertJsonPath('data.sent', 1);

        Mail::assertQueued(JudgingResultMail::class, fn (JudgingResultMail $mail): bool => $mail->hasTo($this->world->owner->email)
            && str_ends_with($mail->url, '/judging_answers/'.$this->world->token()));
        Mail::assertQueuedCount(1);

        $this->putJson($base.'/release', ['released' => false])->assertOk();
        $this->assertFalse($this->world->show->fresh()?->is_judging_released);
        $this->assertContains('judging.release', array_column($this->auditEntries(), 'action'));
    }

    public function test_judges_cannot_release_or_mail(): void
    {
        $this->actingAs($this->world->judge);

        $this->putJson('/admin-api/judging/shows/'.$this->world->show->id.'/release', ['released' => true])->assertForbidden();
        $this->postJson('/admin-api/judging/shows/'.$this->world->show->id.'/results-mail')->assertForbidden();
        $this->assertFalse($this->world->show->fresh()?->is_judging_released);
    }
}
