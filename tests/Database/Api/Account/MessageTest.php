<?php

namespace Tests\Database\Api\Account;

use App\Mail\PrivateMessageMail;
use App\Models\UserPreferences;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class MessageTest extends AccountTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(int $recipientId, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $recipientId,
            'subject' => 'See you at the show',
            'message' => 'Want to caravan down together?',
        ], $overrides);
    }

    #[Test]
    public function anonymous_senders_get_401(): void
    {
        $recipient = $this->makeUser();

        $this->postJson('/member/message', $this->message($recipient->id))->assertUnauthorized();
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_member_can_message_a_member_who_allows_it(): void
    {
        $sender = $this->makeUser(['first' => 'Sam', 'last' => 'Sender', 'email' => 'sam@example.test']);
        $recipient = $this->makeUser(['email' => 'recipient-private@example.test']);

        $this->actingAs($sender)->postJson('/member/message', $this->message($recipient->id))
            ->assertOk()
            ->assertContent('""');

        Mail::assertSent(PrivateMessageMail::class, function (PrivateMessageMail $mail) use ($recipient, $sender) {
            return $mail->hasTo($recipient->email)
                && $mail->hasReplyTo($sender->email)
                && ! $mail->hasTo($sender->email);
        });
    }

    #[Test]
    public function the_recipient_address_is_never_returned(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser(['email' => 'hidden-address@example.test']);

        $this->actingAs($sender)->postJson('/member/message', $this->message($recipient->id))
            ->assertOk()
            ->assertDontSee('hidden-address@example.test');
    }

    #[Test]
    public function an_unknown_recipient_gets_the_invalid_user_answer(): void
    {
        $sender = $this->makeUser();

        $this->actingAs($sender)->postJson('/member/message', $this->message(999999))
            ->assertStatus(401)
            ->assertExactJson(['Invalid User']);
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_deleted_recipient_gets_the_same_answer_as_an_unknown_one(): void
    {
        $sender = $this->makeUser();
        $gone = $this->makeUser();
        $gone->delete();

        $this->actingAs($sender)->postJson('/member/message', $this->message($gone->id))
            ->assertStatus(401)
            ->assertExactJson(['Invalid User']);
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_recipient_who_turned_private_messages_off_gets_the_same_answer(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();
        UserPreferences::query()->where('user_id', $recipient->id)->update(['allow_private_message' => false]);

        $this->actingAs($sender)->postJson('/member/message', $this->message($recipient->id))
            ->assertStatus(401)
            ->assertExactJson(['Invalid User']);
        Mail::assertNothingSent();
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidMessages(): array
    {
        return [
            'subject missing' => ['subject', null],
            'subject with a line break' => ['subject', "Hi\r\nBcc: spy@example.test"],
            'subject too long' => ['subject', str_repeat('s', 151)],
            'body missing' => ['message', null],
            'body too long' => ['message', str_repeat('m', 5001)],
        ];
    }

    #[Test]
    #[DataProvider('invalidMessages')]
    public function invalid_messages_are_refused_and_not_sent(string $field, mixed $value): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();
        $body = $this->message($recipient->id, [$field => $value]);
        if ($value === null) {
            unset($body[$field]);
        }

        $this->actingAs($sender)->postJson('/member/message', $body)->assertStatus(400);
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_failing_mailer_gets_a_legacy_failure_answer(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($sender)->postJson('/member/message', $this->message($recipient->id))
            ->assertStatus(400)
            ->assertExactJson(['Failed to send message']);
    }

    #[Test]
    public function a_sender_is_limited_to_five_messages_a_minute(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($sender)->postJson('/member/message', $this->message($recipient->id))->assertOk();
        }

        $this->actingAs($sender)->postJson('/member/message', $this->message($recipient->id))->assertStatus(429);
    }

    #[Test]
    public function a_post_without_a_csrf_token_gets_419(): void
    {
        $this->app->instance('env', 'local');
        $sender = $this->makeUser();
        $recipient = $this->makeUser();

        $this->actingAs($sender)->withHeader('Accept', 'application/json')
            ->post('/member/message', $this->message($recipient->id))
            ->assertStatus(419);
    }
}
