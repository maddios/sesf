<?php

namespace Tests\Database\Judging;

use App\Enums\JudgingType;
use App\Models\CarShow;
use App\Models\Judging\Answer;
use App\Models\User;
use App\Services\Judging\JudgingQrCodes;
use App\Services\Judging\JudgingResults;
use App\Services\Judging\JudgingScores;

final class JudgingDrawTest extends JudgingTestCase
{
    public function test_scores_sum_live_answers_and_count_missing_questions(): void
    {
        $world = $this->world;
        $this->score($world->entry, ['8', '7.25']);
        Answer::factory()->deleted()->create(['question_id' => $world->q3->id, 'show_registration_car_id' => $world->entry->id, 'user_id' => $world->judge->id, 'data' => '10']);

        $car = app(JudgingScores::class)->forShow($world->show->id)[0];

        $this->assertSame('15.25', $car->score);
        $this->assertSame(3, $car->numQuestions);
        $this->assertSame(1, $car->numEmptyQuestions);
        $this->assertFalse($car->isComplete());
    }

    public function test_the_draw_ranks_the_top_five_flags_ties_and_ignores_other_shows(): void
    {
        $world = $this->world;
        $bodyId = (int) $world->entry->car?->body_id;
        $this->score($world->entry, ['10', '10', '10']);

        foreach (['9', '8', '8', '7', '6', '5'] as $score) {
            $this->score(JudgingWorld::entry($world->show, User::factory()->create(), JudgingType::Clean, $bodyId), [$score, $score, $score]);
        }
        $elsewhere = JudgingWorld::entry(CarShow::factory()->create(), User::factory()->create(), JudgingType::Clean, $bodyId);
        $this->score($elsewhere, ['10', '10', '10']);

        $draw = app(JudgingScores::class)->draw($world->show->id);
        $group = array_values($draw)[0];

        $this->assertCount(1, $draw);
        $this->assertSame(['30', '27', '24', '24', '21'], array_map(fn (array $place): ?string => $place['car']->score, $group));
        $this->assertSame([false, false, true, true, false], array_column($group, 'tie'));
        $this->assertNotContains($elsewhere->id, array_map(fn (array $place): int => $place['car']->id, $group));
    }

    public function test_the_draw_page_marks_incomplete_cars(): void
    {
        $this->actingAs($this->world->judge);
        $this->score($this->world->entry, ['5']);

        $this->get('/judging_draw/'.$this->world->show->id)->assertOk()->assertSee('Incomplete')->assertSee($this->world->token());
    }

    public function test_it_serves_a_qr_png_and_a_pdf_sheet(): void
    {
        $this->actingAs($this->world->admin);

        $png = $this->get('/judging_qr_code/'.$this->world->token())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame("\x89PNG\r\n\x1a\n", substr((string) $png->getContent(), 0, 8));

        $pdf = $this->get('/judging_qr_code/show/'.$this->world->show->id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-', substr((string) $pdf->getContent(), 0, 5));
    }

    public function test_urls_use_app_url_and_the_legacy_id(): void
    {
        config(['app.url' => 'https://sesf.example']);

        $this->assertSame('https://sesf.example/judging/IOgXHXRZVkIlpct3D', JudgingQrCodes::judgingUrl(123));
        $this->assertSame('https://sesf.example/judging_answers/IOgXHXRZVkIlpct3D', JudgingResults::answersUrl(123));
    }
}
