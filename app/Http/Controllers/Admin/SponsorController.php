<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminImageRequest;
use App\Http\Requests\Admin\SponsorRequest;
use App\Http\Resources\Admin\SponsorResource;
use App\Models\CarShow;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use App\Services\Admin\SponsorImages;
use App\Services\Images\ImageUploadException;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * admin-api/sponsors: the reference admin resource (see docs/admin-conventions.md).
 * Replaces the frozennode sponsor.php config and the /sponsor-image-upload/{id} page.
 */
final class SponsorController extends Controller
{
    private const RESOURCE = 'sponsor';

    /** Free text that may hold personal details: logged by field name only. */
    private const REDACT = ['internal_notes'];

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'name', 'URL', 'is_enabled', 'sort_order', 'created_at', 'updated_at'], default: 'name')
            ->searchable(['name', 'URL', 'short_description'])
            ->filterable([
                'is_enabled' => ['eq'],
                'id' => ['eq', 'in'],
                'name' => ['eq', 'like'],
                'URL' => ['like', 'null'],
                'created_at' => ['between'],
            ])
            // Legacy filter: sponsors attached to one show.
            ->filterUsing('show_id', function (Builder $query, string $value): void {
                $query->whereHas('carShow', fn (Builder $shows) => $shows->whereKey(ctype_digit($value) ? (int) $value : 0));
            })
            ->respond(SponsorResource::class);
    }

    public function show(int $sponsor): SponsorResource
    {
        return new SponsorResource($this->find($sponsor));
    }

    public function store(SponsorRequest $request): JsonResponse
    {
        $sponsor = new Sponsor($request->fields());
        $sponsor->forceFill([
            'is_enabled' => $request->boolean('is_enabled'),
            'sort_order' => (int) $request->integer('sort_order'),
        ])->save();

        AuditLog::record('sponsor.create', self::RESOURCE, [$sponsor->id], AuditLog::created($sponsor, self::REDACT));

        return (new SponsorResource($this->find($sponsor->id)))->response()->setStatusCode(201);
    }

    public function update(SponsorRequest $request, int $sponsor): SponsorResource
    {
        $model = $this->find($sponsor);
        $model->fill($request->fields());
        if ($request->has('is_enabled')) {
            $model->forceFill(['is_enabled' => $request->boolean('is_enabled')]);
        }
        if ($request->has('sort_order')) {
            $model->forceFill(['sort_order' => $request->integer('sort_order')]);
        }
        $model->save();

        AuditLog::record('sponsor.update', self::RESOURCE, [$model->id], AuditLog::diff($model, self::REDACT));

        return new SponsorResource($this->find($model->id));
    }

    public function enable(int $sponsor): SponsorResource
    {
        return $this->setEnabled($sponsor, true);
    }

    public function disable(int $sponsor): SponsorResource
    {
        return $this->setEnabled($sponsor, false);
    }

    /**
     * Deletes a sponsor nobody uses. A sponsor attached to a show (show_sponsor) or set as a show's
     * primary sponsor is a 409 with the counts: disable it instead.
     */
    public function destroy(int $sponsor, SponsorImages $images): JsonResponse
    {
        $model = $this->find($sponsor);
        $conflict = fn (int $shows, int $primary) => response()->json([
            'message' => 'This sponsor is used by '.($shows + $primary).' show link(s) and cannot be deleted. Disable it instead.',
            'references' => ['shows' => $shows, 'primary' => $primary],
        ], 409);

        try {
            $result = DB::transaction(function () use ($model): ?array {
                $shows = ShowSponsor::query()->where('sponsor_id', $model->id)->lockForUpdate()->count();
                $primary = CarShow::query()->where('primary_sponsor_id', $model->id)->lockForUpdate()->count();
                if ($shows + $primary > 0) {
                    return [$shows, $primary];
                }
                $model->delete();

                return null;
            });
        } catch (QueryException $e) {
            // A link created by someone else between the check and the delete (FK violation).
            if ((string) $e->getCode() === '23000') {
                return $conflict(1, 0);
            }
            throw $e;
        }

        if ($result !== null) {
            return $conflict($result[0], $result[1]);
        }

        $images->delete($model->id);
        AuditLog::record('sponsor.delete', self::RESOURCE, [$model->id], AuditLog::deleted($model, self::REDACT));

        return response()->json(null, 204);
    }

    /**
     * POST admin-api/sponsors/{id}/image (multipart "file"): replaces the logo at images/sponsor/{id}.jpg.
     */
    public function image(AdminImageRequest $request, int $sponsor, SponsorImages $images): JsonResponse
    {
        $model = $this->find($sponsor);

        try {
            $images->store($request->upload(), $model->id);
        } catch (ImageUploadException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        AuditLog::record('sponsor.image', self::RESOURCE, [$model->id], ['image' => AuditLog::REDACTED]);

        return (new SponsorResource($this->find($model->id)))->response();
    }

    private function setEnabled(int $id, bool $enabled): SponsorResource
    {
        $model = $this->find($id);
        $model->forceFill(['is_enabled' => $enabled])->save();

        AuditLog::record($enabled ? 'sponsor.enable' : 'sponsor.disable', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return new SponsorResource($this->find($model->id));
    }

    /**
     * @return Builder<Sponsor>
     */
    private function query(): Builder
    {
        return Sponsor::query()
            ->withCount(['carShow as shows_count'])
            ->addSelect(['primary_count' => CarShow::query()
                ->selectRaw('count(*)')
                ->whereColumn('car_show.primary_sponsor_id', 'sponsor.id'),
            ]);
    }

    private function find(int $id): Sponsor
    {
        return $this->query()->find($id) ?? abort(404, 'Sponsor not found.');
    }
}
