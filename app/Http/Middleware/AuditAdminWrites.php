<?php

namespace App\Http\Middleware;

use App\Support\Admin\AuditLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias audit.admin, applied to the whole admin-api group (routes/web/admin.php).
 *
 * - Every admin request gets a request id (X-Request-Id echoed on the response).
 * - Safe methods (GET, HEAD, OPTIONS) are not audited.
 * - A successful write (2xx/3xx) whose controller did not call AuditLog::record() still gets a
 *   generic entry (action http.<method>, resource = route name, ids = numeric route parameters),
 *   so no admin write goes unaudited. Controllers should record their own, richer entry.
 */
class AuditAdminWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = AuditLog::requestId($request);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        if ($request->isMethodSafe() || $response->getStatusCode() >= 400) {
            return $response;
        }

        if ($request->attributes->get(AuditLog::RECORDED_ATTRIBUTE) !== true) {
            $route = $request->route();
            $ids = [];
            $name = 'unknown';
            if ($route instanceof Route) {
                $name = (string) ($route->getName() ?? $route->uri());
                foreach ($route->parameters() as $value) {
                    if (is_scalar($value) && ctype_digit((string) $value)) {
                        $ids[] = (int) $value;
                    } elseif ($value instanceof Model) {
                        $ids[] = $value->getKey();
                    }
                }
            }
            AuditLog::record('http.'.strtolower($request->method()), $name, $ids, [], [], $request);
        }

        return $response;
    }
}
