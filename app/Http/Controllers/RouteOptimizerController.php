<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Services\RouteOptimizerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Route optimization screen (02-94): a read-only, BI-style plan that
 * explains why each stop sits at its sequence. The ordering is local
 * and deterministic (zone → district sort → shipment id); no external
 * routing/AI service is called and no distance is invented.
 */
class RouteOptimizerController extends Controller
{
    public function index(Request $request): View
    {
        $plan = app(RouteOptimizerService::class)->plan((int) $request->user()->company_id);

        return view('sales.delivery.routes', ['plan' => $plan]);
    }
}
