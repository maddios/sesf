<?php

namespace Tests\Database\Api\Account;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LegacyTestDatabase;
use Tests\Support\SchemaColumns;

/**
 * Every member-form field that maps to a `user` column must accept exactly the column width and
 * reject one character more, with a 400 (never a 500). Widths are read from the legacy schema.
 */
class RequestWidthsTest extends AccountTestCase
{
    private const EMAIL_SUFFIX = '@example.test';

    /**
     * @return array<string, mixed>
     */
    private static function userColumn(string $name): array
    {
        $schema = SchemaColumns::fromFile(base_path(LegacyTestDatabase::SCHEMA_FILE));

        return $schema->columns('user')[$name];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function widthCases(): array
    {
        return [
            'register first_name' => ['register', 'first_name', 'first', 'name'],
            'register last_name' => ['register', 'last_name', 'last', 'name'],
            'register email' => ['register', 'email', 'email', 'email'],
            'register street' => ['register', 'street', 'address1', 'text'],
            'register street2' => ['register', 'street2', 'address2', 'text'],
            'register city' => ['register', 'city', 'city', 'text'],
            'register state' => ['register', 'state', 'state', 'text'],
            'register zip' => ['register', 'zip', 'zip', 'alnum'],
            'register phone' => ['register', 'phone', 'phone', 'phone'],
            'account first' => ['account', 'first', 'first', 'name'],
            'account last' => ['account', 'last', 'last', 'name'],
            'account email' => ['account', 'email', 'email', 'email'],
            'account phone' => ['account', 'phone', 'phone', 'phone'],
            'location address1' => ['location', 'address1', 'address1', 'text'],
            'location address2' => ['location', 'address2', 'address2', 'text'],
            'location city' => ['location', 'city', 'city', 'text'],
            'location state' => ['location', 'state', 'state', 'alpha2'],
            'location zip' => ['location', 'zip', 'zip', 'alnum'],
        ];
    }

    /**
     * A value of exactly $length characters in the field's own format, or null when the format
     * cannot reach that length (phone is capped at 12 characters by its pattern; the state rule
     * is exactly two letters).
     */
    private static function validValue(string $kind, int $length): ?string
    {
        return match ($kind) {
            'name', 'text', 'alnum' => str_repeat('a', $length),
            'email' => $length > strlen(self::EMAIL_SUFFIX) ? str_repeat('a', $length - strlen(self::EMAIL_SUFFIX)).self::EMAIL_SUFFIX : null,
            'alpha2' => $length === 2 ? 'GA' : null,
            default => null,
        };
    }

    private static function overflowValue(string $kind, int $length): string
    {
        return match ($kind) {
            'email' => str_repeat('a', $length - strlen(self::EMAIL_SUFFIX)).self::EMAIL_SUFFIX,
            'phone' => str_repeat('1', $length),
            default => str_repeat('a', $length),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function submit(string $form, string $field, mixed $value): array
    {
        $response = match ($form) {
            'register' => $this->submitRegistration($field, $value),
            'account' => $this->submitAccountInfo($field, $value),
            'location' => $this->submitLocation($field, $value),
            default => throw new \InvalidArgumentException("Unknown form [{$form}]."),
        };

        return [(string) $response->getStatusCode(), $response->getContent()];
    }

    /** @return TestResponse<Response> */
    private function submitRegistration(string $field, mixed $value): TestResponse
    {
        $this->turnstile(true);
        $overrides = [$field => $value];
        if ($field === 'email') {
            $overrides['email_confirmation'] = $value;
        }

        return $this->postJson('/member/register', $this->registration($overrides));
    }

    /** @return TestResponse<Response> */
    private function submitAccountInfo(string $field, mixed $value): TestResponse
    {
        $user = $this->makeUser();

        return $this->actingAs($user)->postJson('/member/account-info', array_merge([
            'first' => 'Jane',
            'last' => 'Doe',
            'email' => 'fresh.'.bin2hex(random_bytes(4)).'@example.test',
            'phone' => '386-555-1234',
            'current_password' => self::PASSWORD,
        ], [$field => $value]));
    }

    /** @return TestResponse<Response> */
    private function submitLocation(string $field, mixed $value): TestResponse
    {
        $user = $this->makeUser();

        return $this->actingAs($user)->postJson('/member/location', array_merge([
            'address1' => '500 New Street',
            'city' => 'Atlanta',
            'state' => 'GA',
            'zip' => '30309',
        ], [$field => $value]));
    }

    #[Test]
    #[DataProvider('widthCases')]
    public function one_character_over_the_column_width_is_rejected_with_400(string $form, string $field, string $column, string $kind): void
    {
        $width = (int) self::userColumn($column)['length'];

        [$status, $body] = $this->submit($form, $field, self::overflowValue($kind, $width + 1));

        $this->assertSame('400', $status, "{$form}.{$field} at ".($width + 1)." chars: {$body}");
        $this->assertIsArray(json_decode($body, true), "{$form}.{$field} should answer with the error array");
    }

    #[Test]
    #[DataProvider('widthCases')]
    public function a_value_of_exactly_the_column_width_passes_validation(string $form, string $field, string $column, string $kind): void
    {
        $width = (int) self::userColumn($column)['length'];
        $value = self::validValue($kind, $width);

        if ($value === null) {
            $this->markTestSkipped("{$form}.{$field} has a format that cannot reach {$width} characters.");
        }

        $this->assertSame($width, mb_strlen($value));

        [$status, $body] = $this->submit($form, $field, $value);

        $this->assertTrue($status[0] === '2', "{$form}.{$field} at {$width} chars should pass, got {$status}: {$body}");
    }

    #[Test]
    public function latin1_letters_are_accepted(): void
    {
        $this->submitLocation('city', 'Málaga')->assertOk();
    }

    #[Test]
    public function emoji_is_refused_with_a_friendly_message_and_not_saved(): void
    {
        $user = $this->makeUser(['city' => 'Stable']);

        $this->actingAs($user)->postJson('/member/location', [
            'address1' => '500 New Street',
            'city' => 'Atlanta 😀',
            'state' => 'GA',
            'zip' => '30309',
        ])
            ->assertStatus(400)
            ->assertExactJson(['Please use only standard letters (no emoji).']);

        $this->assertSame('Stable', $user->fresh()->city);
    }

    #[Test]
    public function emoji_would_fail_the_database_write_under_strict_mode(): void
    {
        // Documents why the Latin1Text rule exists: the legacy column cannot hold the character.
        $user = $this->makeUser();

        $this->expectException(QueryException::class);

        DB::table('user')->where('id', $user->id)->update(['city' => 'Atlanta 😀']);
    }
}
