<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ReadsGalleryInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\Gallery\GalleryItemResource;
use App\Http\Resources\Gallery\GalleryResource;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\User;
use App\Services\Gallery\GalleryAccess;
use App\Services\Gallery\GalleryItemFeed;
use App\Services\Gallery\GalleryWriter;
use App\Services\Images\ImageUploadException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Gallery writes (POST gallery/*). Ports legacy GalleryController postItems, postCreate,
 * postShowHide, postUploadFile, postDefaultItem and postRemoveItem with their URLs, bodies and
 * status codes. Requires a session (web middleware: CSRF applies).
 */
final class GalleryWriteController extends Controller
{
    use ReadsGalleryInput;

    public function __construct(
        private readonly GalleryWriter $writer,
        private readonly GalleryItemFeed $feed,
    ) {}

    /**
     * POST gallery/items {gallery_id, items: [{id, title?, sort_order?}, ...]}. Saves titles and
     * order, then returns the gallery's full item list. All changes apply together or not at all.
     */
    public function saveItems(Request $request): JsonResponse
    {
        if (! $request->user() instanceof User) {
            return response()->json('Not authorized', 401);
        }

        $gallery = $this->findGallery($request);
        if ($gallery === null) {
            return response()->json('Invalid gallery', 400);
        }

        if (! (new GalleryAccess($request->user()))->canManage($gallery)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'items' => ['present', 'array'],
            'items.*' => ['array'],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.title' => ['sometimes', 'nullable', 'string', 'max:256'],
            'items.*.sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        if (! $this->writer->updateItems($gallery, $this->itemChanges($request))) {
            return response()->json(['error' => 'Item not in gallery'], 400);
        }

        return response()->json(GalleryItemResource::page(
            $request,
            $this->feed->forGallery($gallery, null, false, false, 0),
        ));
    }

    /**
     * POST gallery/create {show_id, title?, gallery_id?}. Admins create a gallery (or a
     * sub-gallery of gallery_id). Members checked in at the show get their own album, created
     * disabled on first call and returned as is afterwards.
     */
    public function create(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['not logged in'], 401);
        }

        $access = new GalleryAccess($user);
        $rules = ['show_id' => ['required', 'integer', Rule::exists('car_show', 'id')]];

        if ($access->isAdmin()) {
            $rules['title'] = ['required', 'string', 'max:256'];
            $rules['gallery_id'] = [
                'sometimes', 'nullable', 'integer',
                Rule::exists('gallery', 'id')->where('show_id', (int) $request->input('show_id')),
            ];
        }

        $validator = Validator::make($request->all(), $rules, [
            'gallery_id.exists' => 'The parent gallery must belong to the same event.',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toArray(), 400);
        }

        $showId = (int) $request->input('show_id');

        if (! $access->isAdmin()) {
            if (! $access->isCheckedIn($showId)) {
                return response()->json(['error' => 'Must be checked in for this event.'], 403);
            }

            return $this->galleryResponse($request, $this->writer->memberAlbum($user, $showId));
        }

        $parentId = $request->filled('gallery_id') ? (int) $request->input('gallery_id') : null;

        return $this->galleryResponse($request, $this->writer->adminCreate($user, $showId, (string) $request->input('title'), $parentId));
    }

    /** POST gallery/show-hide {gallery_id, is_enabled}. Administrators only. */
    public function showHide(Request $request): JsonResponse
    {
        if (! (new GalleryAccess($request->user()))->isAdmin()) {
            return $this->forbidden();
        }

        $gallery = $this->findGallery($request);
        if ($gallery === null) {
            return response()->json('Invalid gallery_id', 400);
        }

        if (Validator::make($request->all(), ['is_enabled' => ['required', 'boolean']])->fails()) {
            return response()->json(['error' => 'is_enabled must be 0 or 1'], 400);
        }

        $gallery->forceFill(['is_enabled' => $request->boolean('is_enabled')])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * POST gallery/upload-file (multipart: file, gallery_id, last_modified, last_modified_date).
     * One photo per request. The image is re-encoded to a metadata-free JPEG by ImageStore.
     */
    public function uploadFile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'Not logged in'], 401);
        }

        $gallery = $this->findGallery($request);
        if ($gallery === null) {
            return response()->json(['error' => 'Invalid gallery'], 400);
        }

        if (! (new GalleryAccess($user))->canManage($gallery)) {
            return $this->forbidden();
        }

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        if (! $file->isValid()) {
            return response()->json(['error' => 'Invalid file upload'], 400);
        }

        $lastModified = $request->input('last_modified_date');

        try {
            $this->writer->upload($gallery, $user, $file, is_string($lastModified) ? $lastModified : null);
        } catch (ImageUploadException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            Log::error('Gallery upload failed', ['gallery_id' => $gallery->id, 'exception' => $e]);

            return response()->json(['error' => 'Failed to save item file'], 500);
        }

        return response()->json(['ok' => true]);
    }

    /** POST gallery/default-item {gallery_id, item_id}. Sets the cover; returns the gallery. */
    public function defaultItem(Request $request): JsonResponse
    {
        if (! $request->user() instanceof User) {
            return response()->json('Not authorized', 401);
        }

        $gallery = $this->findGallery($request);
        if ($gallery === null) {
            return response()->json('Invalid gallery_id', 400);
        }

        if (! (new GalleryAccess($request->user()))->canManage($gallery)) {
            return $this->forbidden();
        }

        $itemId = $this->intInput($request, 'item_id') ?? 0;
        if (! $this->writer->isItemOfGallery($gallery, $itemId)) {
            return response()->json('Item not in gallery', 400);
        }

        return $this->galleryResponse($request, $this->writer->setCover($gallery, $itemId));
    }

    /** POST gallery/remove-item {gallery_id, item_id}. Deletes the photo and its file. */
    public function removeItem(Request $request): JsonResponse
    {
        if (! $request->user() instanceof User) {
            return response()->json('Not authorized', 401);
        }

        $gallery = $this->findGallery($request);
        if ($gallery === null) {
            return response()->json('invalid gallery_id', 400);
        }

        if (! (new GalleryAccess($request->user()))->canManage($gallery)) {
            return $this->forbidden();
        }

        $item = GalleryItem::query()->find($this->intInput($request, 'item_id') ?? 0);
        if ($item === null) {
            return response()->json('invalid item_id', 400);
        }

        if (! $this->writer->isItemOfGallery($gallery, (int) $item->id)) {
            return response()->json('Item not in gallery', 400);
        }

        $this->writer->remove($item);

        return response()->json('', 200);
    }

    /**
     * @return array<int, array{id: int, title?: string, sort_order?: int|null}>
     */
    private function itemChanges(Request $request): array
    {
        $changes = [];

        foreach ((array) $request->input('items', []) as $row) {
            $change = ['id' => (int) $row['id']];

            if (array_key_exists('title', $row)) {
                $change['title'] = (string) ($row['title'] ?? '');
            }

            if (array_key_exists('sort_order', $row)) {
                $change['sort_order'] = $row['sort_order'] === null ? null : (int) $row['sort_order'];
            }

            $changes[] = $change;
        }

        return $changes;
    }

    private function findGallery(Request $request): ?Gallery
    {
        $id = $this->intInput($request, 'gallery_id');

        return $id === null ? null : Gallery::query()->find($id);
    }

    private function galleryResponse(Request $request, Gallery $gallery): JsonResponse
    {
        return response()->json((new GalleryResource($gallery))->resolve($request));
    }
}
