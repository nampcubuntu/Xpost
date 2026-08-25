<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\XAuthException;
use App\Models\XApp;
use App\Services\X\XAppSelector;
use App\Services\X\XOAuth2Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The consent leg of OAuth 2.0. Browser-facing, so these live on the web
 * routes rather than the API -- X redirects a real browser here.
 */
class XOAuthController extends Controller
{
    public function __construct(
        private readonly XOAuth2Service $oauth,
        private readonly XAppSelector $apps,
    ) {}

    public function redirect(Request $request, XApp $xApp): RedirectResponse
    {
        $this->authorize('update', $xApp);

        return redirect()->away(
            $this->oauth->authorizationUrl($xApp, $request->user()->id, $request->query('redirect_to'))
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        // X sends the user back here with ?error=access_denied when they cancel.
        if ($error = $request->query('error')) {
            return redirect('/accounts?oauth_error='.urlencode((string) $error));
        }

        $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        try {
            $account = $this->oauth->handleCallback(
                (string) $request->query('state'),
                (string) $request->query('code'),
            );
        } catch (XAuthException $e) {
            return redirect('/accounts?oauth_error='.urlencode($e->getMessage()));
        }

        // A freshly authorised account has no app budget reserved yet.
        $this->apps->assign($account);

        return redirect('/accounts?connected='.urlencode($account->username));
    }
}
