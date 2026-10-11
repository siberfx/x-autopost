<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Exceptions\NotConnected;
use Siberfx\XAutopost\Exceptions\NotShareable;
use Siberfx\XAutopost\Exceptions\XRequestFailed;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Support\StatusMessages;
use Siberfx\XAutopost\Support\Ui;
use Siberfx\XAutopost\XManager;

final class ShareController extends Controller
{
    /** Alias-only route: a class name from the URL is never used. */
    public function __invoke(Request $request, XManager $x, string $type, string $id): JsonResponse|RedirectResponse
    {
        return $this->share($request, $x, Relation::getMorphedModel($type), $id);
    }

    /** Signed route used by the share button; the app signed the class name, so it may be used. */
    public function signed(Request $request, XManager $x): JsonResponse|RedirectResponse
    {
        $type = (string) $request->query('type', '');

        return $this->share($request, $x, Relation::getMorphedModel($type) ?? $type, (string) $request->query('id', ''));
    }

    private function share(Request $request, XManager $x, ?string $class, string $id): JsonResponse|RedirectResponse
    {
        abort_if($class === null || ! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnX::class), 404);

        /** @var (Model&ShareableOnX)|null $model */
        $model = $class::query()->find($id);
        abort_if($model === null, 404);

        try {
            $post = $x->share($model, XSharedPost::TRIGGER_MANUAL);
        } catch (NotConnected|NotShareable|InvalidArgumentException $e) {
            // InvalidArgumentException: the model's toXPost() built an invalid post.
            return $this->respond($request, 422, $e->getMessage());
        } catch (XRequestFailed $e) {
            return $this->respond($request, 502, $e->getMessage());
        }

        return $this->respond($request, 201, 'Shared on X.', [
            'post_id' => $post->post_id,
            'posted_at' => $post->posted_at?->toIso8601String(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function respond(Request $request, int $status, string $message, array $data = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || ! Ui::enabled()) {
            return response()->json(['message' => $message] + ($data === [] ? [] : ['data' => $data]), $status);
        }

        return back()->with(StatusMessages::FLASH_KEY, ['type' => $status < 300 ? 'success' : 'error', 'message' => $message]);
    }
}
