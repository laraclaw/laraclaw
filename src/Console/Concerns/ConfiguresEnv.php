<?php

namespace LaraClaw\Console\Concerns;

use Closure;
use LaraClaw\Models\Account;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait ConfiguresEnv
{
    /**
     * If the env key already has a value, prompt to use it or set a new one.
     * Secrets are masked in the display.
     */
    private function askEnv(string $label, string $key, bool $secret = false, ?Closure $input = null, string $placeholder = ''): string
    {
        $existing = $this->readEnv($key);

        if ($existing !== null) {
            $display = $secret ? '(hidden)' : $existing;

            if (select($label, ['existing' => "Use existing: {$display}", 'new' => 'Set new value']) === 'existing') {
                return $existing;
            }
        }

        return $input
            ? $input()
            : ($secret
                ? password($label, required: true)
                : text($label, placeholder: $placeholder, required: true));
    }

    /**
     * If an Account already exists for this user and connector, prompt to use it or set a new one.
     */
    private function askAccount(string $label, int|string $userId, string $connector, string $placeholder = ''): string
    {
        $existing = Account::where('user_id', $userId)->where('connector', $connector)->value('account');

        if ($existing !== null && select($label, [
            'existing' => "Use existing: {$existing}",
            'new' => 'Set new value',
        ]) === 'existing') {
            return $existing;
        }

        return text(
            label: $label,
            placeholder: $placeholder,
            required: true,
            validate: fn (string $value): ?string => Account::where('connector', $connector)
                ->where('account', $value)
                ->where('user_id', '!=', $userId)
                ->exists()
                ? 'That account is already registered to another user.'
                : null,
        );
    }

    private function readEnv(string $key): ?string
    {
        $escaped = preg_quote($key, '/');

        if (preg_match('/^' . $escaped . '="?([^"\n]*)"?/m', file_get_contents(base_path('.env')), $m)) {
            return filled($m[1]) ? trim($m[1]) : null;
        }

        return null;
    }

    private function saveEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->writeEnv($key, $value);
        }
    }

    private function writeEnv(string $key, string|int $value): void
    {
        $path = base_path('.env');
        $line = str_contains((string) $value, ' ') ? "{$key}=\"{$value}\"" : "{$key}={$value}";
        $escaped = preg_quote($key, '/');
        $content = file_get_contents($path);

        $content = preg_match("/^{$escaped}=/m", $content)
            ? preg_replace("/^{$escaped}=.*/m", $line, $content)
            : $content . "\n{$line}";

        file_put_contents($path, $content);
    }

    private function heading(string $text): void
    {
        echo "\033[1m{$text}\033[0m" . PHP_EOL . PHP_EOL;
    }
}
