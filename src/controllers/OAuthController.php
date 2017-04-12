<?php

namespace BohSchu\Exact\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class OAuthController extends BaseController
{
    protected $guzzle;

    public function __construct()
    {
        $this->guzzle = new Client(['base_uri' => config('exact.base_uri')]);
    }

    public function login()
    {
        if (Cache::get(Auth::id() . '.access_token') || Cache::get(Auth::id() . '.refresh_token')) {
            return redirect()->to('/dashboard');
        }

        $uri = '/api/oauth2/auth?client_id=' . env('CLIENT_ID')
               . '&redirect_uri=' . env('REDIRECT_URI')
               . '&response_type=code';

        return redirect()->to(config('exact.base_uri') . $uri);
    }

    /**
     * Catch the callback and the code and perform token request
     *
     * @param Request $request
     * @return function
     */
    public function callback(Request $request)
    {
        $body = $this->guzzle->request('POST', '/api/oauth2/token', [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'form_params' => [
                'code' => $request->get('code'),
                'redirect_uri' => env('REDIRECT_URI'),
                'grant_type' => 'authorization_code',
                'client_id' => env('CLIENT_ID'),
                'client_secret' => env('CLIENT_SECRET')
            ]
        ]);

        dd($body);

        Cache::put(Auth::id() . '.access_token', $body['access_token'], 10);
        Cache::put(Auth::id() . '.refresh_token', $body['refresh_token'], 10);

        return redirect()->to('/orders/transfer');
    }
}