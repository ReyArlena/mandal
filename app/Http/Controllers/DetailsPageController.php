<?php

namespace App\Http\Controllers;

use App\Models\DetailsPage;
use Illuminate\Http\Response;

/**
 * Shows a server details page to players, without signing in. Statuses
 * come from the database only; reading a page never contacts a server.
 */
class DetailsPageController extends Controller
{
    public function __invoke(string $slug): Response
    {
        $page = DetailsPage::query()->where('slug', $slug)->with('entries')->first();

        abort_unless($page !== null, 404);

        // X-Robots-Tag comes from PreventIndexing, as on every response.
        return response()
            ->view('details-pages.show', ['page' => $page])
            ->header('Referrer-Policy', 'no-referrer');
    }
}
