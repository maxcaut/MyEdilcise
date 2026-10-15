<?php

namespace App\Http\Middleware;

use App\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyAppSettings
{
    public function __construct(private AppSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $original = config('condominio');
        foreach ($this->settings->values() as $key => $value) {
            if (array_key_exists($key, $original)) {
                config(['condominio.'.$key => $value]);
            }
        }
        try {
            return $next($request);
        } finally {
            config(['condominio' => $original]);
        }
    }
}
