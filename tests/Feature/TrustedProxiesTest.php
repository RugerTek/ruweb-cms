<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** TRUSTED_PROXIES decides whose X-Forwarded-* headers build URLs (docs/operations/deploy-cpanel.md). */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const FORWARDED = [
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'sitio.example.com',
        'HTTP_X_FORWARDED_PORT' => '443',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
    ];

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        parent::tearDown();
    }

    private function loginPage(string $remoteAddr = '127.0.0.1'): string
    {
        return (string) $this->withServerVariables(self::FORWARDED + ['REMOTE_ADDR' => $remoteAddr])
            ->get(route('admin.login'))
            ->assertOk()
            ->getContent();
    }

    public function test_without_trusted_proxies_forwarded_headers_are_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);

        $html = $this->loginPage();

        $this->assertStringNotContainsString('https://sitio.example.com', $html);
        $this->assertStringContainsString('http://localhost', $html);
    }

    public function test_wildcard_trusts_the_connecting_proxy_and_builds_https_urls(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $html = $this->loginPage('10.1.2.3');

        $this->assertStringContainsString('https://sitio.example.com', $html);
        $this->assertStringNotContainsString('http://sitio.example.com', $html);
    }

    public function test_list_of_ips_and_cidr_only_trusts_matching_addresses(): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.10', '10.0.0.0/8']]);

        $this->assertStringContainsString('https://sitio.example.com', $this->loginPage('10.20.30.40'));
        $this->assertStringContainsString('https://sitio.example.com', $this->loginPage('192.0.2.10'));
    }

    public function test_list_ignores_forwarded_headers_from_other_addresses(): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.10', '10.0.0.0/8']]);

        $html = $this->loginPage('198.51.100.7');

        $this->assertStringNotContainsString('https://sitio.example.com', $html);
        $this->assertStringContainsString('http://localhost', $html);
    }

    public function test_env_value_is_parsed_into_the_config_shape(): void
    {
        $this->assertNull($this->configFor(''));
        $this->assertNull($this->configFor('   '));
        $this->assertSame('*', $this->configFor('*'));
        $this->assertSame(['173.245.48.0/20', '10.0.0.1'], $this->configFor(' 173.245.48.0/20 , 10.0.0.1 ,'));
    }

    private function configFor(string $value): mixed
    {
        putenv('TRUSTED_PROXIES='.$value);
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;

        return (require base_path('config/trustedproxy.php'))['proxies'];
    }
}
