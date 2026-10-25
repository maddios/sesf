<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ContactReportRequest;
use App\Mail\ContactReportMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * POST home/report (contact form). Replaces legacy HomeController::postReport.
 *
 * Mail goes to the registration inbox with Reply-To set to the visitor. The legacy log field, which
 * was printed from client-supplied JSON, is not accepted.
 */
class ContactController extends Controller
{
    public function report(ContactReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        /** @var User|null $member */
        $member = $request->user();

        $senderName = $member !== null ? (string) $member->fullName : trim((string) ($data['name'] ?? ''));
        $senderEmail = $member !== null ? (string) $member->email : (string) ($data['email'] ?? '');

        try {
            Mail::to((string) config('sesf.reg_email'))->send(new ContactReportMail(
                messageSubject: (string) $data['subject'],
                senderName: $senderName,
                senderEmail: $senderEmail,
                body: (string) $data['message'],
                location: isset($data['location']) ? (string) $data['location'] : null,
                senderProfileUrl: $member !== null
                    ? rtrim((string) config('app.url'), '/').'/'.$member->profileURL
                    : null,
            ));
        } catch (Throwable $e) {
            Log::warning('Contact report mail failed.', ['exception' => $e::class]);

            return response()->json(['Failed to send message'], 400);
        }

        return response()->json('');
    }
}
