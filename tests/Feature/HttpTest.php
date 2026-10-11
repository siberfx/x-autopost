<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;
use Siberfx\XAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1, 'name' => 'Admin']);
    config(['x-autopost.routes.after_connect' => '/admin/integrations']);
});

function stateFrom($response): string
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['state'];
}

it('requires the configured middleware', function () {
    // JSON requests: Testbench has no "login" route to redirect guests to.
    $this->getJson('/x/redirect')->assertUnauthorized();
    $this->getJson('/x/connection')->assertUnauthorized();
    $this->postJson('/x/share/post/1')->assertUnauthorized();
});

it('redirects to X with a state stored in the session', function () {
    $response = $this->actingAs($this->user)->get('/x/redirect');

    $response->assertRedirectContains('https://x.com/i/oauth2/authorize');
    expect(stateFrom($response))->toHaveLength(40)->toBe(session('x-autopost.state'));
});

it('sends people back when credentials are missing', function () {
    config(['x-autopost.client_id' => null]);

    $this->actingAs($this->user)->get('/x/redirect')
        ->assertRedirect('/admin/integrations?x=not_configured');
});

it('connects on a valid callback', function () {
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000]),
        'https://api.x.com/2/users/me*' => Http::response(['data' => ['username' => 'ada', 'name' => 'Ada']]),
    ]);
    $state = stateFrom($this->actingAs($this->user)->get('/x/redirect'));

    $this->actingAs($this->user)->get('/x/callback?'.http_build_query(['code' => 'c', 'state' => $state]))
        ->assertRedirect('/admin/integrations?x=connected');

    expect(app(TokenStore::class)->get()?->username)->toBe('ada');
});

it('rejects a callback whose state does not match, and one replayed', function () {
    $state = stateFrom($this->actingAs($this->user)->get('/x/redirect'));

    $this->actingAs($this->user)->get('/x/callback?code=c&state=forged')
        ->assertRedirect('/admin/integrations?x=invalid_state');
    $this->actingAs($this->user)->get('/x/callback?'.http_build_query(['code' => 'c', 'state' => $state]))
        ->assertRedirect('/admin/integrations?x=invalid_state'); // state was pulled by the first callback
});

it('passes X errors back', function (array $query, array $expected) {
    $state = stateFrom($this->actingAs($this->user)->get('/x/redirect'));

    $this->actingAs($this->user)->get('/x/callback?'.http_build_query($query + ['state' => $state]))
        ->assertRedirect('/admin/integrations?'.http_build_query($expected));
})->with([
    'scope refused' => [
        ['error' => 'unauthorized_scope_error', 'error_description' => 'Scope "profile" is not authorized for your application'],
        ['x' => 'error', 'reason' => 'Scope "profile" is not authorized for your application'],
    ],
    'cancelled' => [['error' => 'access_denied'], ['x' => 'cancelled']],
]);

it('logs a failed exchange without the code or secret', function () {
    Log::spy();
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_request', 'error_description' => 'authorization code not found'], 401)]);
    $state = stateFrom($this->actingAs($this->user)->get('/x/redirect'));

    $this->actingAs($this->user)->get('/x/callback?'.http_build_query(['code' => 'secret-code', 'state' => $state]))
        ->assertRedirect('/admin/integrations?x=exchange_failed');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'X token exchange failed.'
        && str_contains($context['error'], 'authorization code not found')
        && ! str_contains(json_encode($context), 'secret-code')
        && ! str_contains(json_encode($context), 'client-secret'));
});

it('shows and deletes the connection as JSON', function () {
    Http::fake([

        'https://api.x.com/2/users/me*' => Http::response(['data' => ['username' => 'ada', 'name' => 'Ada']]),
        'https://api.x.com/2/oauth2/revoke' => Http::response('', 200),
    ]);
    app(TokenStore::class)->put(new StoredConnection('member-token', null, connectedAt: CarbonImmutable::now()));

    $this->actingAs($this->user)->getJson('/x/connection')
        ->assertOk()
        ->assertJsonPath('data.connected', true)
        ->assertJsonPath('data.name', 'Ada')
        ->assertJsonMissingPath('data.access_token');

    $this->actingAs($this->user)->deleteJson('/x/connection')
        ->assertOk()
        ->assertJsonPath('revoked', true)
        ->assertJsonPath('data.connected', false);
});

it('shares a model by morph alias', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '3']], 201)]);
    app(TokenStore::class)->put(new StoredConnection('member-token', null));
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);

    $this->actingAs($this->user)->postJson("/x/share/post/{$post->id}")
        ->assertCreated()
        ->assertJsonPath('data.post_id', '3');
});

it('answers 422 when not connected or not live, and 502 when X refuses', function () {
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);
    $draft = Post::query()->create(['title' => 'Draft', 'status' => PostStatus::Draft]);

    $this->actingAs($this->user)->postJson("/x/share/post/{$post->id}")
        ->assertStatus(422)->assertJsonPath('message', 'X is not connected.');

    app(TokenStore::class)->put(new StoredConnection('member-token', null));
    app()->forgetScopedInstances(); // the manager remembers the connection per request; start a new one
    $this->actingAs($this->user)->postJson("/x/share/post/{$draft->id}")->assertStatus(422);

    Http::fake(['https://api.x.com/2/tweets' => Http::response(['message' => 'Rate limited'], 429)]);
    $this->actingAs($this->user)->postJson("/x/share/post/{$post->id}")
        ->assertStatus(502)->assertJsonPath('message', 'X rejected the request: Rate limited');
});

it('answers 404 for anything that is not a known shareable record', function (string $type, string $id) {
    $this->actingAs($this->user)->postJson("/x/share/{$type}/{$id}")->assertNotFound();
})->with([
    'unknown alias' => ['nope', '1'],
    'class name instead of alias' => [urlencode(Post::class), '1'],
    'deleted record' => ['post', '999'],
]);

it('registers its routes by default', function () {
    expect(app('router')->has('x-autopost.callback'))->toBeTrue();
});

it('answers 422 when the model cannot build a valid X post', function () {
    Http::fake();
    Relation::morphMap(['relative-url-post' => RelativeUrlPost::class]);
    app(TokenStore::class)->put(new StoredConnection('member-token', null));
    $post = RelativeUrlPost::query()->create(['title' => 'Hi', 'status' => 'published']);

    $this->actingAs($this->user)->postJson("/x/share/relative-url-post/{$post->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'X posts need an absolute http(s) URL, got [/posts/'.$post->id.'].');
    $this->actingAs($this->user)
        ->postJson(URL::signedRoute('x-autopost.share.signed', ['type' => RelativeUrlPost::class, 'id' => $post->id]))
        ->assertStatus(422);

    Http::assertNothingSent();
    expect(XSharedPost::query()->count())->toBe(0);
});
