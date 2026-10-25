<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ProfileImageRequest;
use App\Models\User;
use App\Services\Images\ImageStore;
use App\Services\Images\ImageUploadException;
use Illuminate\Http\JsonResponse;

/**
 * POST member/profile-image. Replaces legacy MemberProfile::postProfileImage.
 *
 * Success is a JSON empty string with 200, as the legacy client expects (a truthy JSON body is
 * read as an error). Failure is 400 with an array of messages.
 */
final class ProfileImageController extends Controller
{
    public function __construct(private readonly ImageStore $images) {}

    public function store(ProfileImageRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->images->store(
                $request->file('file'),
                (string) config('sesf.member_image_root'),
                (int) $user->id,
                'profile',
            );
        } catch (ImageUploadException $e) {
            return response()->json([$e->getMessage()], 400);
        }

        $user->has_image = true;
        $user->save();

        return response()->json('', 200);
    }
}
