<?php

namespace App\Services\Security;

use App\Models\User;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TwoFactorService
{
    public function createSetupPayload(User $user): array
    {
        $secret = $this->generateSecret();
        $recoveryCodes = $this->generateRecoveryCodes();
        $otpauth = $this->buildOtpAuthUri($user, $secret);

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => json_encode($recoveryCodes),
            'two_factor_confirmed_at' => null,
        ])->save();
        $user->syncSecurityProfile();

        return [
            'secret' => $secret,
            'qr_code_url' => $this->makeQrCodeDataUri($otpauth),
            'recovery_codes' => $recoveryCodes,
        ];
    }

    public function confirmCode(User $user, string $code): array
    {
        if (! $user->two_factor_secret || ! $this->verifyCode($user->two_factor_secret, $code)) {
            throw new \InvalidArgumentException('Invalid verification code.');
        }

        $recoveryCodes = $this->decodeRecoveryCodes($user->two_factor_recovery_codes);

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();
        $user->syncSecurityProfile();

        return $recoveryCodes;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => json_encode($recoveryCodes),
        ])->save();
        $user->syncSecurityProfile();

        return $recoveryCodes;
    }

    public function decodeRecoveryCodes(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    public function verifyCode(string $secret, string $code): bool
    {
        $normalizedCode = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $normalizedCode)) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);

        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals($this->generateTotp($secret, $timeSlice + $offset), $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    private function makeQrCodeDataUri(string $otpauth): string
    {
        $svg = QrCode::format('svg')->size(200)->margin(1)->generate($otpauth);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function buildOtpAuthUri(User $user, string $secret): string
    {
        $issuer = config('app.name', 'TesoTunes');
        $label = rawurlencode($issuer.':'.($user->email ?? 'user-'.$user->id));

        return "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    private function generateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    private function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        }

        return $codes;
    }

    private function generateTotp(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0).pack('N*', $timeSlice);
        $hm = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $hashPart = substr($hm, $offset, 4);
        $unpacked = unpack('N', $hashPart)[1] & 0x7FFFFFFF;

        return str_pad((string) ($unpacked % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper($secret);
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        foreach (str_split($secret) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }
}
