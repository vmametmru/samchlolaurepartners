<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\HttpException;

final class AuthController extends Controller
{
    public static function login(): never
    {
        $input = self::input();
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            self::json(['error' => 'Bad Request', 'message' => 'email and password are required'], 400);
        }

        $result = Auth::login($email, $password);
        if ($result === null) {
            self::json(['error' => 'Unauthorized', 'message' => 'Invalid credentials'], 401);
        }

        self::json($result);
    }

    public static function me(): never
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+/i', $header) && empty($_COOKIE['auth_token'])) {
            self::json(['error' => 'Unauthorized', 'message' => 'Missing token'], 401);
        }

        $user = Auth::user();
        if (!$user) {
            self::json(['error' => 'Unauthorized', 'message' => 'Invalid token'], 401);
        }

        self::json(['data' => $user]);
    }

    public static function pageLogin(): never
    {
        $input = self::input();
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new HttpException(400, 'Bad Request', 'Email et mot de passe requis.');
        }

        $result = Auth::login($email, $password);
        if ($result === null) {
            throw new HttpException(401, 'Unauthorized', 'Email ou mot de passe incorrect.');
        }

        $user = $result['user'];
        $target = ($user['role'] ?? '') === 'admin' ? '/admin/partners' : '/partner/dashboard';
        self::redirect($target, 'Connexion réussie.');
    }

    public static function logout(): never
    {
        Auth::logout();
        self::redirect('/login', 'Vous êtes déconnecté.');
    }
}
