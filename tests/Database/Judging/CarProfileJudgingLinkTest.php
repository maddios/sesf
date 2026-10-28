<?php

namespace Tests\Database\Judging;

use App\Models\CarShow;

final class CarProfileJudgingLinkTest extends JudgingTestCase
{
    private function judgingLink(): ?string
    {
        $this->actingAs($this->world->owner);
        $link = $this->getJson('/car/profile?car_id='.$this->world->entry->car_id)->assertOk()->json('shows.0.judgingLink');

        return $link === null ? null : (string) $link;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function showState(array $state): void
    {
        $this->world->show->forceFill($state)->save();
    }

    public function test_the_owner_gets_the_answers_link_once_results_are_released(): void
    {
        config(['app.url' => 'https://sesf.example']);
        $this->showState(['is_judging_released' => true]);
        $this->score($this->world->entry, [null]);

        $this->assertSame('https://sesf.example/judging_answers/'.$this->world->token(), $this->judgingLink());
    }

    public function test_the_link_appears_once_the_show_has_ended(): void
    {
        $this->showState(['date_start' => now()->subDays(3)->toDateString(), 'date_end' => now()->subDay()->toDateString()]);
        $this->score($this->world->entry, [null]);

        $this->assertNotNull($this->judgingLink());
    }

    public function test_there_is_no_link_before_release(): void
    {
        $this->score($this->world->entry, [null]);

        $this->assertNull($this->judgingLink());
    }

    public function test_there_is_no_link_without_answers(): void
    {
        $this->showState(['is_judging_released' => true]);

        $this->assertNull($this->judgingLink());
    }

    public function test_results_are_visible_when_released_or_after_the_end_date(): void
    {
        $this->assertTrue(CarShow::factory()->make(['is_judging_released' => true])->judgingResultsVisible());
        $this->assertTrue(CarShow::factory()->make(['date_end' => now()->subDay()->toDateString()])->judgingResultsVisible());
        $this->assertFalse(CarShow::factory()->make(['date_end' => now()->toDateString()])->judgingResultsVisible());
        $this->assertFalse(CarShow::factory()->make(['date_end' => null])->judgingResultsVisible());
    }
}
