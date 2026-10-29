<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** A private sales tool: keep it out of search engines and do not advertise the PHP version. */
class PrivateAppHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (function_exists('header_remove') && !headers_sent()) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
