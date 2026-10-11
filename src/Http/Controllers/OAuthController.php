<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Http\Controllers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Siberfx\XAutopost\Exceptions\NotConfigured;
use Siberfx\XAutopost\OAuth\OAuthClient;
use Siberfx\XAutopost\XManager;
use Throwable;

final class OAuthController extends Controller
{
    private const STATE_KEY = 'x-autopost.state';

    private const VERIFIER_KEY = 'x-autopost.code_verifier';

    public function __construct(private readonly XManager $x) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = OAuthClient::newCodeVerifier();

        try {
            $url = $this->x->authorizationUrl($state, OAuthClient::codeChallenge($verifier));
        } catch (NotConfigured) {
            return $this->back('not_configured');
        }

        $request->session()->put(self::STATE_KEY, $state);
        $request->session()->put(self::VERIFIER_KEY, $verifier);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $verifier = $request->session()->pull(self::VERIFIER_KEY);
        $state = $request->query('state');

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state) || ! is_string($verifier)) {
            return $this->back('invalid_state');
        }

        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            if ($error === 'access_denied') {
                return $this->back('cancelled');
            }

            $reason = $request->query('error_description');

            return $this->back('error', ['reason' => Str::limit(is_string($reason) && $reason !== '' ? $reason : $error, 300)]);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back('missing_code');
        }

        try {
            $this->x->completeConnection($code, $verifier);
        } catch (Throwable $e) {
            Log::warning('X token exchange failed.', [
                'error' => $e instanceof RequestException ? $e->response->body() : $e->getMessage(),
            ]);

            return $this->back('exchange_failed');
        }

        return $this->back('connected');
    }

    /** @param  array<string, string>  $extra */
    private function back(string $status, array $extra = []): RedirectResponse
    {
        $target = (string) config('x-autopost.routes.after_connect', '/');
        $glue = str_contains($target, '?') ? '&' : '?';

        return redirect()->to($target.$glue.http_build_query(['x' => $status] + $extra));
    }
}
