<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ReadsGalleryInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\Gallery\GalleryItemResource;
use App\Http\Resources\Gallery\GalleryResource;
use App\Http\Resources\Gallery\GalleryShowResource;
use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Services\Gallery\GalleryAccess;
use App\Services\Gallery\GalleryItemFeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Gallery reads (GET gallery/*). Ports legacy GalleryController getCheckedIn, getCarShowList,
 * getList, getBreadcrumb, getInfo and getItems with their URLs, keys and status codes.
 */
final class GalleryReadController extends Controller
{
    use ReadsGalleryInput;

    public function __construct(private readonly GalleryItemFeed $feed) {}

    /** GET gallery/checked-in?show_id= */
    public function checkedIn(Request $request): JsonResponse
    {
        $access = new GalleryAccess($request->user());
        if ($access->viewer() === null) {
            return response()->json(['checked_in' => false]);
        }

        $showId = $this->intInput($request, 'show_id') ?? 0;
        $album = $access->memberGallery($showId);

        return response()->json([
            'checked_in' => $access->isCheckedIn($showId),
            'member_gallery_id' => $album === null ? null : (int) $album->id,
        ]);
    }

    /**
     * GET gallery/car-show-list. Active shows, newest first. Non-admins see only shows with an
     * enabled gallery. Each show lists the galleries this viewer may see (legacy listed all,
     * disabled ones included).
     */
    public function carShowList(Request $request): JsonResponse
    {
        $access = new GalleryAccess($request->user());

        $shows = CarShow::query()
            ->where('is_active', true)
            ->with(['galleries' => fn ($query) => $query->visibleTo($access->viewerId(), $access->isAdmin())->orderBy('gallery.id')])
            ->when(! $access->isAdmin(), fn (Builder $query) => $query->whereHas('galleries', fn (Builder $gallery) => $gallery->enabled()))
            ->orderByDesc('date_start')
            ->get();

        return response()->json(GalleryShowResource::collection($shows)->resolve($request));
    }

    /**
     * GET gallery/list?show_id=&gallery_id=. Child galleries of gallery_id, or the top-level
     * galleries of the show, with their cover item. Non-admins see enabled galleries only.
     */
    public function list(Request $request): JsonResponse
    {
        $access = new GalleryAccess($request->user());
        $parentId = $this->intInput($request, 'gallery_id');

        if ($parentId !== null) {
            $parent = Gallery::query()->find($parentId);
            if ($parent !== null && ! $access->canView($parent)) {
                return $this->forbidden();
            }
        }

        $galleries = Gallery::query()
            ->with('defaultItem')
            ->forShow($this->intInput($request, 'show_id') ?? 0)
            ->when($parentId === null, fn (Builder $query) => $query->roots(), fn (Builder $query) => $query->where('parent_id', $parentId))
            ->when(! $access->isAdmin(), fn (Builder $query) => $query->enabled())
            ->orderBy('gallery.id')
            ->get();

        return response()->json(GalleryResource::collection($galleries)->resolve($request));
    }

    /**
     * GET gallery/breadcrumb?show_id=&gallery_id=. The show and the gallery's ancestors (root
     * first, the gallery itself excluded). Every gallery on the path must be viewable.
     */
    public function breadcrumb(Request $request): JsonResponse
    {
        $show = CarShow::query()->find($this->intInput($request, 'show_id') ?? 0);
        if ($show === null) {
            return response()->json(['invalid request'], 400);
        }

        $showData = (new GalleryShowResource($show))->resolve($request);
        $gallery = Gallery::query()->find($this->intInput($request, 'gallery_id') ?? 0);

        if ($gallery === null) {
            return response()->json(['car_show' => $showData, 'galleries' => null]);
        }

        $access = new GalleryAccess($request->user());
        $ancestors = $gallery->ancestors();

        if (! $access->canView($gallery) || $ancestors->contains(fn (Gallery $parent): bool => ! $access->canView($parent))) {
            return $this->forbidden();
        }

        return response()->json([
            'car_show' => $showData,
            'galleries' => GalleryResource::collection($ancestors)->resolve($request),
        ]);
    }

    /**
     * GET gallery/info?gallery_id=. The gallery with its cover item. An empty 200 body when the
     * id is missing or unknown, as legacy returned NULL.
     */
    public function info(Request $request): JsonResponse|Response
    {
        $id = $this->intInput($request, 'gallery_id');
        $gallery = $id === null ? null : Gallery::query()->with('defaultItem')->find($id);

        if ($gallery === null) {
            return response('', 200);
        }

        if (! (new GalleryAccess($request->user()))->canView($gallery)) {
            return $this->forbidden();
        }

        return response()->json((new GalleryResource($gallery))->resolve($request));
    }

    /**
     * GET gallery/items?gallery_id=&show_id=&get_subcategory_items=&skip=&all_items=
     * Without gallery_id, get_subcategory_items + show_id returns the items of every gallery
     * of the show the viewer may see.
     */
    public function items(Request $request): JsonResponse
    {
        $access = new GalleryAccess($request->user());
        $showId = $this->intInput($request, 'show_id');
        $withSub = $request->boolean('get_subcategory_items');
        $allItems = $request->boolean('all_items');
        $skip = $this->intInput($request, 'skip') ?? 0;

        if (! $request->filled('gallery_id')) {
            if ($withSub && $showId !== null) {
                return $this->itemsResponse($request, $this->feed->rollupForShow($showId, $access->visibleGalleryIds($showId), $allItems, $skip));
            }

            return response()->json(['error' => 'gallery_id required'], 400);
        }

        $gallery = Gallery::query()->find($this->intInput($request, 'gallery_id') ?? 0);
        if ($gallery === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (! $access->canView($gallery)) {
            return $this->forbidden();
        }

        return $this->itemsResponse($request, $this->feed->forGallery($gallery, $showId, $withSub, $allItems, $skip));
    }

    /**
     * @param  array{items: Collection<int, GalleryItem>, count: int, per_page: int}  $result
     */
    private function itemsResponse(Request $request, array $result): JsonResponse
    {
        return response()->json(GalleryItemResource::page($request, $result));
    }
}
