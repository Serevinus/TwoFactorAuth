<?php

declare(strict_types=1);

namespace Serevinus\Auth;

use function hash_equals;

use Serevinus\Auth\Providers\Qr\IQRCodeProvider;
use Serevinus\Auth\Providers\Rng\CSRNGProvider;
use Serevinus\Auth\Providers\Rng\IRNGProvider;
use Serevinus\Auth\Providers\Time\HttpTimeProvider;
use Serevinus\Auth\Providers\Time\ITimeProvider;
use Serevinus\Auth\Providers\Time\LocalMachineTimeProvider;
use Serevinus\Auth\Providers\Time\NTPTimeProvider;
use SensitiveParameter;

// Based on / inspired by: https://github.com/PHPGangsta/GoogleAuthenticator
// Algorithms, digits, period etc. explained: https://github.com/google/google-authenticator/wiki/Key-Uri-Format
class TwoFactorAuth
{
    private static string $_base32dict = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567=';

    /** @var array<string> */
    private static array $_base32;

    /** @var array<string, int> */
    private static array $_base32lookup = array();

    public function __construct(
        private IQRCodeProvider    $qrcodeprovider,
        private readonly ?string   $issuer = null,
        private readonly int       $digits = 6,
        private readonly int       $period = 30,
        private readonly Algorithm $algorithm = Algorithm::Sha1,
        private ?IRNGProvider      $rngprovider = null,
        private ?ITimeProvider     $timeprovider = null
    ) {
        if ($this->digits <= 0) {
            throw new TwoFactorAuthException('Digits must be > 0');
        }

        if ($this->period <= 0) {
            throw new TwoFactorAuthException('Period must be int > 0');
        }

        self::$_base32 = str_split(self::$_base32dict);
        self::$_base32lookup = array_flip(self::$_base32);
    }

    /**
     * Create a new secret
     */
    public function createSecret(int $bits = 160): string
    {
        $secret = '';
        $bytes = (int)ceil($bits / 5);   // We use 5 bits of each byte (since we have a 32-character 'alphabet' / BASE32)
        $rngprovider = $this->getRngProvider();
        $rnd = $rngprovider->getRandomBytes($bytes);
        for ($i = 0; $i < $bytes; $i++) {
            $secret .= self::$_base32[ord($rnd[$i]) & 31];  //Mask out left 3 bits for 0-31 values
        }
        return $secret;
    }

    /**
     * Calculate the code with given secret and point in time
     */
    public function getCode(#[SensitiveParameter] string $secret, ?int $time = null): string
    {
        $secretkey = $this->base32Decode($secret);

        $timestamp = "\0\0\0\0" . pack('N*', $this->getTimeSlice($this->getTime($time)));  // Pack time into binary string
        $hashhmac = hash_hmac($this->algorithm->value, $timestamp, $secretkey, true);             // Hash it with users secret key
        $hashpart = substr($hashhmac, ord(substr($hashhmac, -1)) & 0x0F, 4);               // Use last nibble of result as index/offset and grab 4 bytes of the result
        $value = unpack('N', $hashpart);                                                   // Unpack binary value
        $value = $value[1] & 0x7FFFFFFF;                                                   // Drop MSB, keep only 31 bits

        return str_pad((string)($value % 10 ** $this->digits), $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Check if the code is correct. This will accept codes starting from ($discrepancy * $period) sec ago to ($discrepancy * period) sec from now
     */
    public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $time = null, ?int &$timeslice = 0): bool
    {
        $timestamp = $this->getTime($time);

        $timeslice = 0;

        // To keep safe from timing-attacks we iterate *all* possible codes even though we already may have
        // verified a code is correct. We use the timeslice variable to hold either 0 (no match) or the timeslice
        // of the match. Each iteration we either set the timeslice variable to the timeslice of the match
        // or set the value to itself.  This is an effort to maintain constant execution time for the code.
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $ts = $timestamp + ($i * $this->period);
            $slice = $this->getTimeSlice($ts);
            $timeslice = hash_equals($this->getCode($secret, $ts), $code) ? $slice : $timeslice;
        }

        return $timeslice > 0;
    }

    /**
     * Get data-uri of QRCode
     */
    public function getQRCodeImageAsDataUri(string $label, #[SensitiveParameter] string $secret, int $size = 200): string
    {
        if ($size <= 0) {
            throw new TwoFactorAuthException('Size must be > 0');
        }

        return 'data:'
            . $this->qrcodeprovider->getMimeType()
            . ';base64,'
            . base64_encode($this->qrcodeprovider->getQRCodeImage($this->getQRText($label, $secret), $size));
    }

    /**
     * Compare default timeprovider with specified timeproviders and ensure the time is within the specified number of seconds (leniency)
     * @param array<ITimeProvider> $timeproviders
     * @throws TwoFactorAuthException
     */
    public function ensureCorrectTime(?array $timeproviders = null, int $leniency = 5): void
    {
        if ($timeproviders === null) {
            $timeproviders = array(
                new NTPTimeProvider(),
                new HttpTimeProvider(),
            );
        }

        // Get default time provider
        $timeprovider = $this->getTimeProvider();

        // Iterate specified time providers
        foreach ($timeproviders as $t) {
            if (!($t instanceof ITimeProvider)) {
                throw new TwoFactorAuthException('Object does not implement ITimeProvider');
            }

            // Get time from default time provider and compare to specific time provider and throw if time difference is more than specified number of seconds leniency
            if (abs($timeprovider->getTime() - $t->getTime()) > $leniency) {
                throw new TwoFactorAuthException(sprintf('Time for timeprovider is off by more than %d seconds when compared to %s', $leniency, get_class($t)));
            }
        }
    }

    /**
     * Builds a string to be encoded in a QR code
     */
    public function getQRText(string $label, #[SensitiveParameter] string $secret): string
    {
        [$label, $user] = explode(':', $label, 2) + ['', null];
        return 'otpauth://totp/' . rawurlencode($label)
            . (!$user ? '' : ':' . rawurlencode($user))
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode((string)$this->issuer)
            . '&period=' . $this->period
            . '&algorithm=' . rawurlencode(strtoupper($this->algorithm->value))
            . '&digits=' . $this->digits;
    }

    /**
     * @throws TwoFactorAuthException
     */
    public function getRngProvider(): IRNGProvider
    {
        return $this->rngprovider ??= new CSRNGProvider();
    }

    public function getTimeProvider(): ITimeProvider
    {
        // Set default time provider if none was specified
        return $this->timeprovider ??= new LocalMachineTimeProvider();
    }

    private function getTime(?int $time = null): int
    {
        return $time ?? $this->getTimeProvider()->getTime();
    }

    private function getTimeSlice(?int $time = null, int $offset = 0): int
    {
        return (int)floor($time / $this->period) + ($offset * $this->period);
    }

    private function base32Decode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[^' . preg_quote(self::$_base32dict, '/') . ']/', $value) !== 0) {
            throw new TwoFactorAuthException('Invalid base32 string');
        }

        $buffer = '';
        foreach (str_split($value) as $char) {
            if ($char !== '=') {
                $buffer .= str_pad(decbin(self::$_base32lookup[$char]), 5, '0', STR_PAD_LEFT);
            }
        }
        $length = strlen($buffer);
        $blocks = trim(chunk_split(substr($buffer, 0, $length - ($length % 8)), 8, ' '));

        $output = '';
        foreach (explode(' ', $blocks) as $block) {
            $output .= chr(bindec(str_pad($block, 8, '0', STR_PAD_RIGHT)));
        }
        return $output;
    }
}
