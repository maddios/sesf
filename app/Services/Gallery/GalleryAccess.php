<?php

namespace App\Services\Gallery;

use App\Models\Gallery;
use App\Models\ShowRegistration;
use App\Models\User;

/**
 * Gallery access rules for one viewer. Ports the private helpers of legacy GalleryController.
 *
 *  - view: administrators, anyone when the gallery is enabled, and the owner of a disabled gallery;
 *  - manage (upload, reorder, cover, remove): administrators and the owner;
 *  - show/hide: administrators only;
 *  - members may open their own album only when checked in (not cancelled) at the show.
 */
final class GalleryAccess
{
    public function __construct(private readonly ?User $viewer) {}

    public function viewer(): ?User
    {
        return $this->viewer;
    }

    public function isAdmin(): bool
    {
        return $this->viewer !== null && $this->viewer->isAdmin();
    }

    public function viewerId(): ?int
    {
        return $this->viewer === null ? null : (int) $this->viewer->id;
    }

    public function canView(Gallery $gallery): bool
    {
        return $this->isAdmin() || $gallery->is_enabled || $this->owns($gallery);
    }

    public function canManage(Gallery $gallery): bool
    {
        return $this->isAdmin() || $this->owns($gallery);
    }

    public function isCheckedIn(int $showId): bool
    {
        if ($this->viewer === null || $showId < 1) {
            return false;
        }

        $registration = ShowRegistration::query()
            ->where('show_id', $showId)
            ->where('user_id', $this->viewer->id)
            ->where('is_cancelled', false)
            ->first();

        return $registration !== null && $registration->is_checked_in;
    }

    /**
     * Ids of the show's galleries this viewer may see (any depth).
     *
     * @return array<int, int>
     */
    public function visibleGalleryIds(int $showId): array
    {
        return Gallery::query()
            ->forShow($showId)
            ->visibleTo($this->viewerId(), $this->isAdmin())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** The viewer's own top-level album for a show. */
    public function memberGallery(int $showId): ?Gallery
    {
        if ($this->viewer === null) {
            return null;
        }

        return Gallery::query()->forShow($showId)->roots()->where('user_id', $this->viewer->id)->first();
    }

    private function owns(Gallery $gallery): bool
    {
        return $this->viewer !== null && (int) $gallery->user_id === (int) $this->viewer->id;
    }
}
