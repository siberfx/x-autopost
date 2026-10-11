<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Posts;

use InvalidArgumentException;

/**
 * What a model wants posted to X: a link plus the text above it.
 *
 * X allows 280 characters per post and counts every URL as 23, so text()
 * shortens the description to fit. A custom commentary may use {title},
 * {description} and {url}; when it still does not fit, text() throws.
 */
final class XPost
{
    public const LIMIT = 280;

    public const URL_LENGTH = 23;

    private const TITLE_LIMIT = 200;

    private string $description = '';

    /** Null: the default layout "title, description, link". */
    private ?string $commentary = null;

    private function __construct(
        private readonly string $url,
        private readonly string $title,
    ) {}

    /** @throws InvalidArgumentException When the URL is not an absolute http(s) URL. */
    public static function make(string $url, string $title): self
    {
        $normalised = self::normalise($url);
        $scheme = strtolower((string) parse_url($normalised, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($normalised, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("X posts need an absolute http(s) URL, got [{$url}].");
        }

        return new self($normalised, $title);
    }

    /**
     * Converts an internationalised host to punycode (when ext-intl is
     * installed) and percent-encodes every byte outside printable ASCII, so
     * https://şirket.com.tr/blog/çalışma becomes a URL X accepts.
     */
    private static function normalise(string $url): string
    {
        $url = trim($url);

        if (function_exists('idn_to_ascii')
            && preg_match('~^([a-z][a-z0-9+.\-]*://)([^/?#]*)(.*)$~is', $url, $parts) === 1
            && preg_match('/[^\x00-\x7F]/', $parts[2]) === 1) {
            $at = strrpos($parts[2], '@');
            $userinfo = $at === false ? '' : substr($parts[2], 0, $at + 1);
            $hostAndPort = $at === false ? $parts[2] : substr($parts[2], $at + 1);
            $port = preg_match('/:\d*$/', $hostAndPort, $match) === 1 ? $match[0] : '';
            $host = idn_to_ascii(substr($hostAndPort, 0, strlen($hostAndPort) - strlen($port)), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($host !== false) {
                $url = $parts[1].$userinfo.$host.$port.$parts[3];
            }
        }

        return (string) preg_replace_callback('/[^\x21-\x7E]/', fn (array $byte): string => rawurlencode($byte[0]), $url);
    }

    public function description(string $description): self
    {
        $copy = clone $this;
        $copy->description = $description;

        return $copy;
    }

    /** Post text with {title}, {description} and {url} filled in. */
    public function commentary(string $template): self
    {
        $copy = clone $this;
        $copy->commentary = $template;

        return $copy;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, self::TITLE_LIMIT);
    }

    /**
     * The text to post, within X's limit.
     *
     * @throws InvalidArgumentException When a custom commentary cannot fit even without its description.
     */
    public function text(): string
    {
        $title = $this->title();
        $description = trim($this->description);

        if ($this->commentary === null) {
            $room = self::LIMIT - self::URL_LENGTH - 4 - mb_strlen($title);
            $body = self::fit($description, $room);

            return $body === ''
                ? $title."\n\n".$this->url
                : $title."\n\n".$body."\n\n".$this->url;
        }

        $without = $this->render($this->commentary, $title, '');
        $body = self::fit($description, self::LIMIT - $this->weigh($without));
        $text = $this->render($this->commentary, $title, $body);

        if ($this->weigh($text) > self::LIMIT) {
            throw new InvalidArgumentException('The X post is '.$this->weigh($text).' characters; the limit is '.self::LIMIT.'.');
        }

        return $text;
    }

    private function render(string $template, string $title, string $description): string
    {
        return strtr($template, ['{title}' => $title, '{description}' => $description, '{url}' => $this->url]);
    }

    /** Length as X counts it: every occurrence of the URL is 23 characters. */
    private function weigh(string $text): int
    {
        return mb_strlen(str_replace($this->url, str_repeat('x', self::URL_LENGTH), $text));
    }

    /** Cut to at most $max characters, ending in an ellipsis when cut. */
    private static function fit(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
