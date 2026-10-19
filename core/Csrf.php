<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::token() . '">';
    }

    public static function verify(?string $token): bool
    {
        if (!$token || empty($_SESSION['_csrf'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf'], $token);
    }
}
