<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie authentication for the React frontend.
        $middleware->statefulApi();

        // Browser (non-JSON) guests land on the SPA login page.
        $middleware->redirectGuestsTo('/login');

        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Consistent API error envelope; technical details never leak to clients.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => __('Unauthenticated.'),
                    'errors' => [],
                ], 401);
            }

            if ($e instanceof AuthorizationException
                || $e instanceof AccessDeniedHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => __('You are not allowed to perform this action.'),
                    'errors' => [],
                ], 403);
            }

            if ($e instanceof ModelNotFoundException
                || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => __('Resource not found.'),
                    'errors' => [],
                ], 404);
            }

            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : __('Request failed.'),
                    'errors' => [],
                ], $e->getStatusCode());
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : __('Something went wrong. Our team has been notified.'),
                'errors' => [],
            ], 500);
        });
    })->create();
