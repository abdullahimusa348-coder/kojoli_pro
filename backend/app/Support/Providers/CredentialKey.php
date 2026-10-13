<?php

namespace App\Support\Providers;

/**
 * Generic credential fields a provider may need. Values are stored encrypted
 * and never displayed; which ones a provider requires is a non-secret
 * setting on the provider.
 */
enum CredentialKey: string
{
    case ApiKey = 'api_key';
    case SecretKey = 'secret_key';
    case Username = 'username';
    case Password = 'password';
    case Pin = 'pin';
    case Token = 'token';

    public function label(): string
    {
        return match ($this) {
            self::ApiKey => 'API key',
            self::SecretKey => 'Secret key',
            self::Username => 'Username',
            self::Password => 'Password',
            self::Pin => 'PIN',
            self::Token => 'Token',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
