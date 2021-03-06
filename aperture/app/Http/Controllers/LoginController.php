<?php
namespace App\Http\Controllers;

use Request, DB;
use Auth;
use IndieAuth;
use App\User;
use App\Jobs\VerifyAPIRegistration;

class LoginController extends Controller
{

  public function login() {
    return view('login/login', [
      'return' => Request::input('return')
    ]);
  }

  public function logout() {
    Auth::logout();
    return redirect('/');
  }

  public function start() {
    if(!Request::input('url')) {
      return redirect('login')->with('auth_error', 'invalid url')
        ->with('auth_error_description', 'The URL you entered was not valid');
    }

    // Discover the endpoints
    $url = Request::input('url');
    $url = IndieAuth\Client::normalizeMeURL($url);
    $url = IndieAuth\Client::resolveMeURL($url); // follows redirects and uses the canonical one

    if(env('PUBLIC_ACCESS') == false) {
      $check = User::where('url', $url)->first();
      if(!$check) {
        return redirect('login')->with('auth_error', 'invalid url')
          ->with('auth_error_description', 'Sorry, you do not have an account here');
      }
    }

    $authorizationEndpoint = IndieAuth\Client::discoverAuthorizationEndpoint($url);
    $tokenEndpoint = IndieAuth\Client::discoverTokenEndpoint($url);
    $micropubEndpoint = IndieAuth\Client::discoverMicropubEndpoint($url);

    if(!$authorizationEndpoint) {
      return redirect('login')->with('auth_error', 'missing authorization endpoint')
        ->with('auth_error_description', 'Could not find your authorization endpoint')
        ->with('auth_url', Request::input('url'));
    }

    if(!$tokenEndpoint) {
      return redirect('login')->with('auth_error', 'missing token endpoint')
        ->with('auth_error_description', 'Could not find your token endpoint. Aperture uses this to verify access tokens sent to its Microsub endpoint by other clients.')
        ->with('auth_url', Request::input('url'));
    }

    $state = str_random(32);
    session([
      'state' => $state,
      'authorization_endpoint' => $authorizationEndpoint,
      'token_endpoint' => $tokenEndpoint,
      'micropub_endpoint' => $micropubEndpoint,
      'indieauth_url' => $url,
      'redirect_after_login' => Request::input('return'),
    ]);

    $redirect_uri = route('login_callback');
    $client_id = route('index');
    $scope = 'read'; // Request "read" scope so that Aperture can get a token to fetch the Micropub config 
    $authorizationURL = IndieAuth\Client::buildAuthorizationURL($authorizationEndpoint, $url, $redirect_uri, $client_id, $state, $scope);

    return redirect($authorizationURL);
  }

  public function callback() {
    if(!session('state')) {
      return redirect('/');
    }

    if(!Request::input('state')) {
      return view('login/error', [
        'error' => 'missing state',
        'description' => 'No state was provided in the callback. The IndieAuth server may be configured incorrectly.'
      ]);
    }

    if(Request::input('state') != session('state')) {
      return view('login/error', [
        'error' => 'invalid state',
        'description' => 'The state returned in the callback did not match the expected value. The IndieAuth server may be configured incorrectly.'
      ]);
    }

    // Check the authorization code at the endpoint previously discovered
    $auth = IndieAuth\Client::getAccessToken(session('token_endpoint'), Request::input('code'), session('indieauth_url'), route('login_callback'), route('index'));

    if(isset($auth['me'])) {

      // Check that the URL returned is on the same domain as the expected URL
      if(parse_url($auth['me'], PHP_URL_HOST) != parse_url(session('indieauth_url'), PHP_URL_HOST)) {
        return view('login/error', [
          'error' => 'invalid user',
          'description' => 'The URL for the user returned did not match the domain of the user initially signing in'
        ]);
      }

      $auth['me'] = IndieAuth\Client::normalizeMeURL($auth['me']);

      // Load or create the user record
      $user = User::where('url', $auth['me'])->first();
      if(!$user) {
        $user = new User();
        $user->url = $auth['me'];
      }

      $user->token_endpoint = session('token_endpoint');

      if(session('micropub_endpoint') && isset($auth['access_token'])) {
        $user->micropub_endpoint = session('micropub_endpoint');
        $user->reload_micropub_config($auth['access_token']);
      }

      $user->save();

      session([
        'access_token' => $auth['access_token'] ?? false,
        'state' => false,
        'authorization_endpoint' => false,
        'token_endpoint' => false,
        'micropub_endpoint' => false,
        'indieauth_url' => false
      ]);

      Auth::login($user);

      if($r=session('redirect_after_login')) {
        session()->forget('redirect_after_login');
        return redirect($r);
      } else {
        return redirect(route('dashboard'));
      }

    } else {
      return view('login/error', [
        'error' => 'indieauth error',
        'description' => 'The authoriation code was not able to be verified'
      ]);
    }
  }

  public function api_register() {
    if(env('PUBLIC_ACCESS') == false) {
      return 'This server does not allow public registrations';
    }

    /*
      Inputs:
      * code
      * site
      * verification_endpoint

      A site like a WordPress site can send a POST request here to register for an account.
      The "site" URL will be used as the identity.

      Aperture will send the code and a challenge to the verification endpoint in a POST,
      and expects a response with just the challenge string. Aperture will then create
      the account, and send a second POST request to the verification endpoint with
      the URL of the Microsub endpoint created for the user.
    */

    VerifyAPIRegistration::dispatch(Request::input('site'), Request::input('code'), Request::input('verification_endpoint'), Request::input('via'));
  }

}
