<?php

declare(strict_types=1);

final class MailSettingsAuth
{
    public function __construct(private readonly string $passwordHash)
    {
    }

    /**
     * @param array<string, string> $envFileValues
     */
    public static function fromEnvironment(array $envFileValues): self
    {
        $envHash = getenv('MAIL_SETTINGS_PASSWORD_HASH');

        return new self(is_string($envHash) && $envHash !== ''
            ? $envHash
            : ($envFileValues['MAIL_SETTINGS_PASSWORD_HASH'] ?? ''));
    }

    public function isConfigured(): bool
    {
        return $this->passwordHash !== '';
    }

    public function verify(string $password): bool
    {
        return $this->passwordHash !== '' && password_verify($password, $this->passwordHash);
    }
}
