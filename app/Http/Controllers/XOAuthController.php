<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\XAuthException;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\X\XAppSelector;
use App\Services\X\XOAuth1Service;
use App\Services\X\XOAuth2Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The consent leg of both OAuth flows. Browser-facing, so these live on the web
 * routes rather than the API -- X redirects a real browser here.
 *
 * Which flow an account is connected through decides what it can do later:
 * OAuth 2.0 is enough to publish, OAuth 1.0a is the only one the Ads API will
 * talk to, and so the only one that can mint its own `video_website` card.
 */
class XOAuthController extends Controller
{
    public function __construct(
        private readonly XOAuth2Service $oauth,
        private readonly XOAuth1Service $oauth1,
        private readonly XAppSelector $apps,
    ) {}

    public function redirect(Request $request, XApp $xApp): RedirectResponse
    {
        $this->authorize('update', $xApp);

        return redirect()->away(
            $this->oauth->authorizationUrl($xApp, $request->user()->id, $request->query('redirect_to'))
        );
    }

    /** The OAuth 1.0a entry point, for accounts that need to mint their own cards. */
    public function redirectOAuth1(Request $request, XApp $xApp): RedirectResponse
    {
        $this->authorize('update', $xApp);

        try {
            $url = $this->oauth1->authorizationUrl(
                $xApp,
                $request->user()->id,
                $request->query('redirect_to'),
                // Connecting a run of different accounts is the normal case, so
                // the login screen is shown unless the caller says otherwise.
                forceLogin: $request->query('force_login') !== '0',
            );
        } catch (XAuthException $e) {
            return redirect('/accounts?oauth_error='.urlencode($e->getMessage()));
        }

        return redirect()->away($url);
    }

    /**
     * One callback URL for both flows.
     *
     * X allows only a fixed list of callback URLs per app, and every one of them
     * has to be registered by hand -- so the two flows share a single
     * registration and are told apart by what X puts on the query string.
     */
    public function callback(Request $request): RedirectResponse
    {
        // X sends the user back here with ?error=access_denied when they cancel
        // OAuth 2.0, and with ?denied=<token> when they cancel OAuth 1.0a.
        if ($error = ($request->query('error') ?: $request->query('denied'))) {
            return redirect('/accounts?oauth_error='.urlencode((string) $error));
        }

        if ($request->filled('oauth_token') && $request->filled('oauth_verifier')) {
            return $this->finish(fn () => $this->oauth1->handleCallback(
                (string) $request->query('oauth_token'),
                (string) $request->query('oauth_verifier'),
            ));
        }

        $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        return $this->finish(fn () => $this->oauth->handleCallback(
            (string) $request->query('state'),
            (string) $request->query('code'),
        ));
    }

    /** @param  callable(): XAccount  $exchange */
    private function finish(callable $exchange): RedirectResponse
    {
        try {
            $account = $exchange();
        } catch (XAuthException $e) {
            return redirect('/accounts?oauth_error='.urlencode($e->getMessage()));
        }

        // A freshly authorised account has no app budget reserved yet.
        $this->apps->assign($account);

        return redirect('/accounts?connected='.urlencode($account->username));
    }
}
