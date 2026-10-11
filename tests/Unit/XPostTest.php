<?php

declare(strict_types=1);

use Siberfx\XAutopost\Posts\XPost;

it('keeps the link as the last line and counts it as 23 characters', function () {
    $url = 'https://example.com/'.str_repeat('a', 200);
    $post = XPost::make($url, 'Headline')->description(str_repeat('word ', 200));

    $text = $post->text();

    expect(str_ends_with($text, "\n\n".$url))->toBeTrue()
        ->and(mb_strlen(str_replace($url, str_repeat('x', 23), $text)))->toBeLessThanOrEqual(280)
        ->and($text)->toContain('…');
});

it('fits a long title and description within 280 characters', function () {
    $url = 'https://example.com/x';
    $text = XPost::make($url, str_repeat('ü', 300))->description(str_repeat('é', 400))->text();

    expect(mb_strlen($text))->toBeLessThanOrEqual(280)
        ->and(mb_substr($text, 0, 200))->toBe(str_repeat('ü', 200));
});

it('builds a custom commentary and fills the description to fit', function () {
    $url = 'https://example.com/x';
    $post = XPost::make($url, 'Launch')
        ->description(str_repeat('d', 500))
        ->commentary("New: {title}\n\n{description}\n\n{url}");

    $text = $post->text();

    expect(str_starts_with($text, "New: Launch\n\n"))->toBeTrue()
        ->and(str_ends_with($text, "\n\n".$url))->toBeTrue()
        ->and(mb_strlen(str_replace($url, str_repeat('x', 23), $text)))->toBeLessThanOrEqual(280);
});

it('throws when a custom commentary cannot fit even without its description', function () {
    XPost::make('https://example.com/x', str_repeat('t', 200))
        ->commentary(str_repeat('z', 280).' {title} {url}')
        ->text();
})->throws(InvalidArgumentException::class, 'limit is 280');

it('is immutable', function () {
    $base = XPost::make('https://example.com/x', 'X');
    $changed = $base->description('changed');

    expect($base->text())->toBe("X\n\nhttps://example.com/x")
        ->and($changed->text())->toBe("X\n\nchanged\n\nhttps://example.com/x");
});

it('percent-encodes a non-ASCII path, query and fragment', function () {
    $post = XPost::make('https://example.com/blog/çalışma?q=ünlü #bölüm', 'X');

    expect($post->url())->toBe('https://example.com/blog/%C3%A7al%C4%B1%C5%9Fma?q=%C3%BCnl%C3%BC%20#b%C3%B6l%C3%BCm');
});

it('leaves an already encoded URL alone', function () {
    expect(XPost::make('https://example.com/blog/%C3%A7al?x=1&y=2', 'X')->url())
        ->toBe('https://example.com/blog/%C3%A7al?x=1&y=2');
});

it('rejects a relative or non-http url', function (string $url) {
    XPost::make($url, 'X');
})->with([
    'relative' => ['/blog/post'],
    'ftp' => ['ftp://example.com/file'],
    'javascript' => ['javascript:alert(1)'],
])->throws(InvalidArgumentException::class);

it('limits the title to 200 characters', function () {
    expect(mb_strlen(XPost::make('https://example.com/x', str_repeat('ü', 300))->title()))->toBe(200);
});
