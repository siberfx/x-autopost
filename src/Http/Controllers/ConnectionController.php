<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Siberfx\XAutopost\Support\StatusMessages;
use Siberfx\XAutopost\Support\Ui;
use Siberfx\XAutopost\XManager;

final class ConnectionController extends Controller
{
    public function __construct(private readonly XManager $x) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->x->connection()->toArray()]);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $revoked = $this->x->disconnect();
        $message = $revoked
            ? 'X disconnected.'
            : 'X disconnected here, but X did not confirm revoking the token. Remove the app under X → Settings → Data privacy → Permitted services if needed.';

        if (! $request->expectsJson() && Ui::enabled()) {
            return back()->with(StatusMessages::FLASH_KEY, ['type' => 'success', 'message' => $message]);
        }

        return response()->json([
            'message' => $message,
            'revoked' => $revoked,
            'data' => $this->x->connection()->toArray(),
        ]);
    }
}
