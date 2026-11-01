<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks search engines never to index, follow or cache anything the panel
 * serves, the public server details pages included. robots.txt also
 * disallows everything, for crawlers that read it before fetching.
 */
class PreventIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
