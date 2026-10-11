<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;
use Siberfx\XAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1]);
    app(TokenStore::class)->put(new StoredConnection('member-token', null));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('disconnects from a form and redirects back with a message', function () {
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::response('', 200)]);

    $this->actingAs($this->user)->from('/admin/settings')->delete('/x/connection')
        ->assertRedirect('/admin/settings')
        ->assertSessionHas('x-autopost.flash', ['type' => 'success', 'message' => 'X disconnected.']);
});

it('shares through the signed url without a morph map entry', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '4']], 201)]);
    $url = URL::signedRoute('x-autopost.share.signed', ['type' => Post::class, 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('x-autopost.flash', ['type' => 'success', 'message' => 'Shared on X.']);

    expect(XSharedPost::query()->for($this->post)->posted()->value('post_id'))->toBe('4');
});

it('rejects a signed url whose type or id was changed', function () {
    Http::fake();
    $url = URL::signedRoute('x-autopost.share.signed', ['type' => Post::class, 'id' => $this->post->id]);

    $this->actingAs($this->user)->post(str_replace('id='.$this->post->id, 'id=999', $url))->assertForbidden();
    $this->actingAs($this->user)->post(str_replace(urlencode(Post::class), urlencode(GenericUser::class), $url))->assertForbidden();
    Http::assertNothingSent();
});

it('flashes X errors on a form share', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['message' => 'Rate limited'], 429)]);
    $url = URL::signedRoute('x-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('x-autopost.flash', ['type' => 'error', 'message' => 'X rejected the request: Rate limited']);
});

it('still answers JSON to JSON requests on the signed route', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '6']], 201)]);
    $url = URL::signedRoute('x-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->postJson($url)->assertCreated()->assertJsonPath('data.post_id', '6');
});

it('flashes an error when the model cannot build a valid X post', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Hi', 'status' => 'published']);
    $url = URL::signedRoute('x-autopost.share.signed', ['type' => RelativeUrlPost::class, 'id' => $post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('x-autopost.flash', ['type' => 'error', 'message' => 'X posts need an absolute http(s) URL, got [/posts/'.$post->id.'].']);
    Http::assertNothingSent();
});
