<?php

declare(strict_types=1);

namespace TestsDependency;

use PHPUnit\Framework\TestCase;
use Serevinus\Auth\Algorithm;
use Serevinus\Auth\Providers\Qr\EndroidQrCodeProvider;
use Serevinus\Auth\Providers\Qr\HandlesDataUri;
use Serevinus\Auth\TwoFactorAuth;

class EndroidQRCodeTest extends TestCase
{
    use HandlesDataUri;

    public function testDependency(): void
    {
        $qr = new EndroidQrCodeProvider();
        $tfa = new TwoFactorAuth($qr, 'Test&Issuer', 6, 30, Algorithm::Sha1);
        $data = $this->DecodeDataUri($tfa->getQRCodeImageAsDataUri('Test&Label', 'VMR466AB62ZBOKHE'));
        $this->assertSame('image/png', $data['mimetype']);
        $this->assertSame('base64', $data['encoding']);
        $this->assertNotEmpty($data['data']);
    }
}
