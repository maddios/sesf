<?php

namespace Tests\Database\Judging;

use App\Models\Judging\Answer;
use PHPUnit\Framework\Attributes\DataProvider;

final class JudgingScoringTest extends JudgingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->world->judge);
    }

    private function storedScore(int $questionId): ?string
    {
        $value = Answer::query()->forRegistrationCar($this->world->entry->id)->forQuestion($questionId)->value('data');

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function surveyData(): array
    {
        return (array) $this->getJson('/judgingData/'.$this->world->token())->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function elementsByName(array $data, int $page, int $panel): array
    {
        $elements = [];
        foreach ($data['form'][$page]['elements'][$panel]['elements'] as $element) {
            $elements[$element['name']] = $element;
        }

        return $elements;
    }

    public function test_it_builds_the_survey_pages_with_current_answers(): void
    {
        $world = $this->world;
        $this->score($world->entry, ['7.25']);
        Answer::query()->update(['comment' => 'Clean']);

        $data = $this->surveyData();
        $q1 = $this->elementsByName($data, 0, 0);
        $q2 = $this->elementsByName($data, 0, 1);

        $this->assertSame(1, $data['showPage']);
        $this->assertSame(['page1', 'page2'], array_column($data['form'], 'name'));
        $this->assertSame('Exterior', $data['form'][0]['title']);
        $this->assertSame(7, $q1['q_'.$world->q1->id]['defaultValue']);
        $this->assertSame('Clean', $q1['comment_'.$world->q1->id]['defaultValue']);
        $this->assertArrayNotHasKey('qtr_'.$world->q1->id, $q1);
        $this->assertArrayHasKey('qtr_'.$world->q2->id, $q2);
    }

    public function test_it_resumes_on_page_2_once_page_1_is_scored(): void
    {
        $this->score($this->world->entry, ['5', '5']);

        $this->getJson('/judgingData/'.$this->world->token())->assertJsonPath('showPage', 2);
    }

    public function test_it_autosaves_a_score_and_comment_as_the_judge(): void
    {
        $world = $this->world;

        $this->submit(['q_'.$world->q1->id => 8, 'comment_'.$world->q1->id => 'Nice paint'])
            ->assertOk()
            ->assertJson(['success' => true, 'savedQuestionIds' => [$world->q1->id], 'firstIncompletePage' => 1]);

        $answer = Answer::query()->forRegistrationCar($world->entry->id)->sole();
        $this->assertSame('8', $answer->data);
        $this->assertSame('Nice paint', $answer->comment);
        $this->assertSame($world->judge->id, $answer->user_id);
    }

    public function test_quarter_points_add_to_the_score_and_it_is_capped_at_10(): void
    {
        $q2 = 'q_'.$this->world->q2->id;
        $qtr = 'qtr_'.$this->world->q2->id;
        $id = $this->world->q2->id;

        $this->submit([$q2 => 7, $qtr => 0.25])->assertOk();
        $this->assertSame('7.25', $this->storedScore($id));

        $this->submit([$qtr => 0.75])->assertOk();
        $this->assertSame('7.75', $this->storedScore($id));

        $this->submit([$q2 => 10, $qtr => 0.5])->assertOk();
        $this->assertSame('10', $this->storedScore($id));

        $this->submit([$q2 => null])->assertOk();
        $this->assertNull($this->storedScore($id));
    }

    /**
     * Keys use {q1} and {q2} for the world's question ids.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'too high' => [['q_{q1}' => 11]],
            'below min' => [['q_{q1}' => 0]],
            'fraction' => [['q_{q1}' => 7.5]],
            'quarter not allowed' => [['q_{q1}' => 7, 'qtr_{q1}' => 0.25]],
            'odd quarter' => [['q_{q2}' => 7, 'qtr_{q2}' => 0.3]],
            'unknown question' => [['q_999999' => 5]],
            'emoji comment' => [['comment_{q1}' => "Great \u{1F600}"]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_invalid_values_and_saves_nothing(array $payload): void
    {
        $data = [];
        foreach ($payload as $key => $value) {
            $data[str_replace(['{q1}', '{q2}'], [(string) $this->world->q1->id, (string) $this->world->q2->id], $key)] = $value;
        }

        $response = $this->submit($data)->assertStatus(400);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, Answer::query()->count());
    }

    public function test_a_later_page_is_refused_while_an_earlier_page_is_unscored(): void
    {
        $world = $this->world;

        $this->submit(['q_'.$world->q3->id => 6])
            ->assertStatus(400)
            ->assertJson(['success' => false, 'firstIncompletePage' => 1]);

        $this->submit(['q_'.$world->q1->id => 6, 'q_'.$world->q2->id => 6, 'q_'.$world->q3->id => 6])
            ->assertOk()
            ->assertJsonPath('firstIncompletePage', null);
    }

    public function test_one_answer_per_question_is_kept_across_judges(): void
    {
        $this->submit(['q_'.$this->world->q1->id => 4])->assertOk();

        $this->actingAs($this->world->admin);
        $this->submit(['q_'.$this->world->q1->id => 9])->assertOk();

        $answer = Answer::query()->forRegistrationCar($this->world->entry->id)->sole();
        $this->assertSame('9', $answer->data);
        $this->assertSame($this->world->admin->id, $answer->user_id);
    }

    public function test_unknown_keys_are_ignored(): void
    {
        $this->submit(['evil' => 'x', 'q_1abc' => 3])->assertOk()->assertJson(['success' => true, 'savedQuestionIds' => []]);
    }

    public function test_the_answers_page_shows_total_and_page_sums(): void
    {
        $world = $this->world;
        $this->submit(['q_'.$world->q1->id => 8, 'q_'.$world->q2->id => 7, 'qtr_'.$world->q2->id => 0.5, 'q_'.$world->q3->id => 9])->assertOk();

        $this->get('/judging_answers/'.$world->token())
            ->assertOk()
            ->assertSeeInOrder(['Scores', '24.5', 'Exterior', '15.5', 'Interior', '9']);
    }
}
