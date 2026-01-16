<?php

namespace App\Http\Middleware;

use App\Http\Resources\User\UserResource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\File;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');
        
        // Recuperiamo il locale impostato dal SetLocaleMiddleware
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'version' => config('app.version'),
            
            // Dati per Vue I18n
            'locale' => app()->getLocale(),

            'auth.user' => fn () => $request->user()
                ? new UserResource($request->user())
                : null,

            'flash' => [
                'message' => fn () => $request->session()->get('message'),
            ],

            'csrf_token' => fn () => $request->user() 
                ? csrf_token() 
                : null,

            'back_url' => fn () => $request->method() === 'GET'
                ? url()->previous()
                : null,

            'quote' => ['message' => trim($message), 'author' => trim($author)],
        ];
    }

}