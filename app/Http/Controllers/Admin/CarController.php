<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminImageRequest;
use App\Http\Requests\Admin\CarRequest;
use App\Http\Resources\Admin\CarResource;
use App\Models\Car;
use App\Models\CarBody;
use App\Models\CarType;
use App\Models\ShowRegistrationCar;
use App\Services\Admin\CarImages;
use App\Services\Images\ImageUploadException;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * admin-api/cars: the owner's cars (legacy frozennode car.php config). Cars are created by members, so there is
 * no store route here. Confirm/unconfirm is the car's is_confirmed flag. A car entered in any show cannot be
 * deleted (409 with the count): it stays in the show history.
 */
final class CarController extends Controller
{
    private const RESOURCE = 'car';

    /** Free-text fields logged by name only. */
    private const REDACT = ['nickname'];

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'model', 'year', 'VIN', 'nickname', 'is_confirmed', 'created_at'], default: 'id')
            ->searchable(['model', 'nickname', 'VIN'])
            ->filterable([
                'id' => ['eq', 'in'],
                'user_id' => ['eq', 'null'],
                'type_id' => ['eq', 'in'],
                'body_id' => ['eq', 'in'],
                'is_confirmed' => ['eq'],
                'year' => ['eq', 'like'],
                'model' => ['like'],
                'VIN' => ['like'],
                'nickname' => ['like'],
            ])
            // Legacy filter: cars entered in one show.
            ->filterUsing('show_id', function (Builder $query, string $value): void {
                $query->whereShowId(ctype_digit($value) ? (int) $value : 0);
            })
            ->respond(CarResource::class);
    }

    public function show(int $car): CarResource
    {
        return new CarResource($this->find($car));
    }

    /**
     * Type and body choices for the edit form: {data: {types: [{id, label}], bodies: [{id, label}]}}.
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'types' => CarType::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (CarType $type) => ['id' => $type->id, 'label' => $type->name])->values(),
            'bodies' => CarBody::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (CarBody $body) => ['id' => $body->id, 'label' => (string) $body->name])->values(),
        ]]);
    }

    public function update(CarRequest $request, int $car): CarResource
    {
        $model = $this->find($car);
        $fields = $request->fields();
        $model->fill($fields);
        $model->forceFill(['user_id' => $fields['user_id']])->save();

        AuditLog::record('car.update', self::RESOURCE, [$model->id], AuditLog::diff($model, self::REDACT));

        return new CarResource($this->find($model->id));
    }

    public function destroy(int $car, CarImages $images): JsonResponse
    {
        $model = $this->find($car);

        try {
            $registrations = DB::transaction(function () use ($model): int {
                $count = ShowRegistrationCar::query()->where('car_id', $model->id)->lockForUpdate()->count();
                if ($count === 0) {
                    $model->delete();
                }

                return $count;
            });
        } catch (QueryException $e) {
            // A registration created between the check and the delete (FK violation).
            if ((string) $e->getCode() === '23000') {
                return $this->inUse(1);
            }
            throw $e;
        }

        if ($registrations > 0) {
            return $this->inUse($registrations);
        }

        $images->delete($model->id);
        AuditLog::record('car.delete', self::RESOURCE, [$model->id]);

        return response()->json(null, 204);
    }

    public function confirm(int $car): CarResource
    {
        return $this->setConfirmed($car, true);
    }

    public function unconfirm(int $car): CarResource
    {
        return $this->setConfirmed($car, false);
    }

    /**
     * POST admin-api/cars/{id}/image (multipart "file"): replaces the car's profile photo.
     */
    public function image(AdminImageRequest $request, int $car, CarImages $images): JsonResponse
    {
        $model = $this->find($car);

        try {
            $images->store($request->upload(), $model->id);
        } catch (ImageUploadException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        $model->forceFill(['has_image' => true])->save();
        AuditLog::record('car.image', self::RESOURCE, [$model->id], ['image' => AuditLog::REDACTED]);

        return (new CarResource($this->find($model->id)))->response();
    }

    private function setConfirmed(int $id, bool $confirmed): CarResource
    {
        $model = $this->find($id);
        $model->forceFill(['is_confirmed' => $confirmed])->save();

        AuditLog::record($confirmed ? 'car.confirm' : 'car.unconfirm', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return new CarResource($this->find($model->id));
    }

    private function inUse(int $registrations): JsonResponse
    {
        return response()->json([
            'message' => "This car is entered in {$registrations} show registration(s) and cannot be deleted.",
            'references' => ['registrations' => $registrations],
        ], 409);
    }

    /**
     * @return Builder<Car>
     */
    private function query(): Builder
    {
        return Car::query()
            ->with(['user:id,first,last,email', 'type:id,name', 'body:id,name'])
            ->withCount('registrations');
    }

    private function find(int $id): Car
    {
        return $this->query()->find($id) ?? abort(404, 'Car not found.');
    }
}
