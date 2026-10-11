<?php

declare(strict_types=1);

namespace Siberfx\XAutopost;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\Connection;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Events\Connected;
use Siberfx\XAutopost\Events\Disconnected;
use Siberfx\XAutopost\Events\Shared;
use Siberfx\XAutopost\Exceptions\NotConnected;
use Siberfx\XAutopost\Exceptions\NotShareable;
use Siberfx\XAutopost\Exceptions\XRequestFailed;
use Siberfx\XAutopost\Jobs\ShareOnX;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\OAuth\OAuthClient;
use Siberfx\XAutopost\Posts\PostsClient;

final class XManager
{
    public function __construct(
        private readonly TokenStore $store,
        private readonly OAuthClient $oauth,
        private readonly PostsClient $posts,
    ) {}

    /** The manager is scoped per request/job, so this is read (query + decrypt) at most once each. */
    private ?StoredConnection $stored = null;

    private bool $storedLoaded = false;

    public function isConnected(): bool
    {
        return $this->stored() !== null;
    }

    public function connection(): Connection
    {
        $stored = $this->stored();

        if ($stored === null) {
            return Connection::disconnected();
        }

        $cached = Cache::get($this->cacheKey($stored));

        if (is_array($cached)) {
            return Connection::fromCache($cached);
        }

        $connection = $this->fetchStatus($stored);
        $this->remember($stored, $connection);

        return $connection;
    }

    /**
     * Post the model now. Re-sharing an already posted model is allowed.
     *
     * @throws NotConnected|NotShareable|XRequestFailed
     * @throws InvalidArgumentException When the model's toXPost() builds an invalid post.
     */
    public function share(Model&ShareableOnX $model, string $trigger = XSharedPost::TRIGGER_MANUAL): XSharedPost
    {
        $stored = $this->stored() ?? throw new NotConnected;

        if (! $model->isLiveForX()) {
            throw new NotShareable;
        }

        $post = $model->toXPost();

        $id = $this->withRefresh($stored, fn (StoredConnection $current): ?string => $this->posts->create($current->accessToken, $post));

        $record = XSharedPost::query()->create([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'post_id' => $id,
            'status' => XSharedPost::STATUS_POSTED,
            'trigger' => $trigger,
            'posted_at' => CarbonImmutable::now(),
        ]);

        event(new Shared($model, $record));

        return $record;
    }

    public function queue(Model&ShareableOnX $model): void
    {
        $job = new ShareOnX($model->getMorphClass(), $model->getKey());

        dispatch($job
            ->onConnection(config('x-autopost.autopost.queue_connection'))
            ->onQueue(config('x-autopost.autopost.queue')));
    }

    public function wasPosted(Model $model): bool
    {
        return XSharedPost::query()->for($model)->posted()->exists();
    }

    /** @return bool Whether X confirmed the revocation; the token is forgotten either way. */
    public function disconnect(): bool
    {
        $stored = $this->stored();
        $revoked = $stored === null || $this->oauth->revoke($stored->accessToken);

        if ($stored !== null) {
            Cache::forget($this->cacheKey($stored));
        }

        $this->store->forget();
        $this->forgetStored();
        event(new Disconnected($revoked));

        return $revoked;
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return $this->oauth->authorizationUrl($state, $codeChallenge);
    }

    /** @throws XRequestFailed */
    public function completeConnection(string $code, string $codeVerifier): Connection
    {
        $stored = $this->oauth->exchange($code, $codeVerifier);
        $this->store->put($stored);
        $this->forgetStored();

        $connection = Connection::fromStored($stored, 'active');
        $this->remember($stored, $connection);
        event(new Connected($connection));

        return $connection;
    }

    /**
     * Run $send with the stored access token. When X answers 401 the tokens are
     * refreshed once and the call is repeated; when that fails too, the
     * connection is marked expired and the original error is thrown.
     *
     * @template T
     *
     * @param  Closure(StoredConnection): T  $send
     * @return T
     *
     * @throws XRequestFailed
     */
    private function withRefresh(StoredConnection $stored, Closure $send): mixed
    {
        try {
            return $send($stored);
        } catch (XRequestFailed $e) {
            if (! $e->isUnauthorized()) {
                throw $e;
            }

            $fresh = $this->refreshed($stored);

            if ($fresh === null) {
                $this->remember($stored, Connection::fromStored($stored, 'expired'));

                throw $e;
            }

            return $send($fresh);
        }
    }

    /** Trade the stored refresh token for a new pair, store both and return the renewed connection. Null when X refuses. */
    private function refreshed(StoredConnection $stored): ?StoredConnection
    {
        if ($stored->refreshToken === null) {
            return null;
        }

        $tokens = $this->oauth->refresh($stored->refreshToken);

        if ($tokens === null) {
            return null;
        }

        $fresh = $stored->withTokens($tokens['access_token'], $tokens['refresh_token']);
        $this->store->put($fresh);
        $this->forgetStored();

        return $fresh;
    }

    private function stored(): ?StoredConnection
    {
        if (! $this->storedLoaded) {
            $this->stored = $this->store->get();
            $this->storedLoaded = true;
        }

        return $this->stored;
    }

    private function forgetStored(): void
    {
        $this->stored = null;
        $this->storedLoaded = false;
    }

    private function fetchStatus(StoredConnection $stored): Connection
    {
        $account = $this->oauth->profile($stored->accessToken);

        if ($account['status'] === 'expired' && ($fresh = $this->refreshed($stored)) !== null) {
            $stored = $fresh;
            $account = $this->oauth->profile($fresh->accessToken);
        }

        $user = $account['user'];

        return new Connection(
            connected: true,
            status: $account['status'],
            name: self::string($user['name'] ?? null) ?? $stored->name,
            username: self::string($user['username'] ?? null) ?? $stored->username,
            picture: self::string($user['profile_image_url'] ?? null) ?? $stored->picture,
            scopes: $stored->scopes,
            connectedAt: $stored->connectedAt,
        );
    }

    /** Only plain arrays go into the cache; see Connection::toCache(). */
    private function remember(StoredConnection $stored, Connection $connection): void
    {
        Cache::put($this->cacheKey($stored), $connection->toCache(), $this->cacheTtl());
    }

    private function cacheKey(StoredConnection $stored): string
    {
        return 'x-autopost:status:'.hash('sha256', $stored->accessToken);
    }

    private function cacheTtl(): int
    {
        return (int) config('x-autopost.status_cache_ttl', 300);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
