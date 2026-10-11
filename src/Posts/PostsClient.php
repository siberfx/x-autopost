<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Posts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\XAutopost\Exceptions\XRequestFailed;

/** The only class that publishes to X: POST /2/tweets. */
final class PostsClient
{
    private const URL = 'https://api.x.com/2/tweets';

    /**
     * @return string|null The post id; null when X accepted the post without returning its id.
     *
     * @throws XRequestFailed
     */
    public function create(string $accessToken, XPost $post): ?string
    {
        $text = $post->text();

        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('x-autopost.http.timeout', 20))
                ->acceptJson()
                ->post(self::URL, ['text' => $text]);
        } catch (ConnectionException $e) {
            throw new XRequestFailed('Could not reach X: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw XRequestFailed::fromResponse($response);
        }

        // A 2xx means the post exists; never fail it for a missing id, or a retry would post twice.
        $id = $response->json('data.id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
