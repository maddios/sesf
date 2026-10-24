<?php

use App\Models\Concerns\HasLegacyDates;
use Illuminate\Database\Eloquent\Model;

test('zero, null and empty dates read as null without touching the database', function (string $zero) {
    $model = new class extends Model
    {
        use HasLegacyDates;

        protected $table = 'car';

        protected $casts = ['when' => 'datetime', 'day' => 'date'];
    };

    $model->setRawAttributes(['when' => $zero, 'day' => $zero]);

    expect($model->getAttribute('when'))->toBeNull()
        ->and($model->getAttribute('day'))->toBeNull()
        ->and($model->toArray())->toMatchArray(['when' => null, 'day' => null]);
})->with([
    'zero datetime' => ['0000-00-00 00:00:00'],
    'zero date' => ['0000-00-00'],
    'empty string' => [''],
]);

test('zero dates are written back unchanged and null stays null', function () {
    $model = new class extends Model
    {
        use HasLegacyDates;

        protected $table = 'car';

        protected $casts = ['when' => 'datetime'];
    };

    $model->setAttribute('when', '0000-00-00 00:00:00');
    expect($model->getAttributes()['when'])->toBe('0000-00-00 00:00:00');

    $model->setAttribute('when', null);
    expect($model->getAttributes()['when'])->toBeNull();
});
