<?php

namespace Tests\Database\Judging;

use App\Enums\JudgingType;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\CarShowJudge;
use App\Models\Judging\Form;
use App\Models\Judging\FormQuestion;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Support\Judging\JudgingId;
use Illuminate\Support\Facades\DB;

/**
 * One show with a judged car and every kind of actor for the authorization matrix:
 * anonymous, member, owner (registered the car), otherJudge (judge assigned to another show),
 * judge (assigned to this show) and admin. The Clean form has q1 and q2 (quarter points) on page 1
 * and q3 on page 2 (the seeded Clean form, with its questions replaced).
 */
final class JudgingWorld
{
    public const ACTORS = ['anonymous', 'member', 'owner', 'other judge', 'assigned judge', 'admin'];

    public function __construct(
        public CarShow $show,
        public ShowRegistrationCar $entry,
        public User $owner,
        public User $member,
        public User $judge,
        public User $otherJudge,
        public User $admin,
        public FormQuestion $q1,
        public FormQuestion $q2,
        public FormQuestion $q3,
    ) {}

    /**
     * @param  array<string, mixed>  $showState
     */
    public static function create(array $showState = []): self
    {
        $show = CarShow::factory()->create($showState);
        $owner = User::factory()->create();
        $entry = self::entry($show, $owner, JudgingType::Clean);

        $judge = User::factory()->judge()->create();
        CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => $show->id]);
        $otherJudge = User::factory()->judge()->create();
        CarShowJudge::factory()->create(['user_id' => $otherJudge->id]);

        DB::table('judging_show_body')->insert([
            'judging_type_id' => JudgingType::Clean->value,
            'car_body_id' => $entry->car?->body_id,
            'car_show_id' => $show->id,
        ]);

        // The lookup fixture seeds one form per type (as in production); its questions are swapped
        // for three known ones. The test transaction rolls this back.
        $form = Form::query()->where('type_id', JudgingType::Clean->value)->orderBy('id')->firstOrFail();
        FormQuestion::query()->where('form_id', $form->id)->delete();
        $question = fn (array $state): FormQuestion => FormQuestion::factory()->create(['form_id' => $form->id] + $state);

        return new self(
            show: $show,
            entry: $entry,
            owner: $owner,
            member: User::factory()->create(),
            judge: $judge,
            otherJudge: $otherJudge,
            admin: User::factory()->admin()->create(),
            q1: $question(['page' => 1, 'page_name' => 'Exterior', 'sort_order' => 1]),
            q2: $question(['page' => 1, 'page_name' => 'Exterior', 'sort_order' => 2, 'has_quarter' => true]),
            q3: $question(['page' => 2, 'page_name' => 'Interior', 'sort_order' => 1]),
        );
    }

    public static function entry(CarShow $show, User $owner, ?JudgingType $type, ?int $bodyId = null): ShowRegistrationCar
    {
        $registration = ShowRegistration::factory()->create(['show_id' => $show->id, 'user_id' => $owner->id]);
        $car = Car::factory()->create(['user_id' => $owner->id] + ($bodyId === null ? [] : ['body_id' => $bodyId]));

        return ShowRegistrationCar::factory()->create([
            'registration_id' => $registration->id,
            'car_id' => $car->id,
            'judging_type_id' => $type,
        ])->load('car');
    }

    public function actor(string $who): ?User
    {
        return match ($who) {
            'anonymous' => null,
            'member' => $this->member,
            'owner' => $this->owner,
            'other judge' => $this->otherJudge,
            'assigned judge' => $this->judge,
            'admin' => $this->admin,
            default => throw new \InvalidArgumentException($who),
        };
    }

    public function token(): string
    {
        return JudgingId::encode($this->entry->id);
    }
}
