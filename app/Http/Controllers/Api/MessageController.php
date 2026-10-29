<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SendPrivateMessageRequest;
use App\Mail\PrivateMessageMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * POST member/message. Replaces legacy MemberProfile::postMessage.
 *
 * The recipient must exist, must not be soft-deleted, and must allow private messages. Legacy
 * never checked the last two. Every refusal gets the same "Invalid User" answer, so the response
 * does not reveal which accounts exist or allow messages. The recipient's address is never returned.
 */
class MessageController extends Controller
{
    public function send(SendPrivateMessageRequest $request): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();
        $data = $request->validated();

        $recipient = User::query()
            ->whereKey((int) $data['user_id'])
            ->whereHas('preferences', fn ($query) => $query->where('allow_private_message', true))
            ->first();

        if ($recipient === null) {
            return response()->json(['Invalid User'], 401);
        }

        try {
            Mail::to($recipient->email)->send(new PrivateMessageMail(
                recipient: $recipient,
                sender: $sender,
                messageSubject: (string) $data['subject'],
                body: (string) $data['message'],
            ));
        } catch (Throwable $e) {
            Log::error('Private message mail failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['Failed to send message'], 400);
        }

        return response()->json('');
    }
}
