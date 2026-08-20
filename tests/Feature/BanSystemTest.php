<?php

namespace Tests\Feature;

use App\Models\BannedUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BanSystemTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $ip = '192.168.1.100'): User
    {
        return User::factory()->create(['last_ip' => $ip]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function banned_ip_redirects_to_banned_page()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        
        BannedUser::banIdentifier('ip', '127.0.0.1', 'Test ban');
        
        $response = $this->get('/profile');
        
        $response->assertRedirect(route('banned'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function banned_cookie_redirects_to_banned_page()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        
        $cookieValue = hash('sha256', 'test_cookie_value');
        BannedUser::banIdentifier('cookie', $cookieValue, 'Test ban');
        
        $response = $this->withCookie('trust_token', $cookieValue)->get('/profile');
        
        $response->assertRedirect(route('banned'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function banned_user_gets_cookie_and_ip_banned()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $user->ban('Test ban reason');
        
        $response = $this->get('/profile');
        
        $response->assertRedirect(route('banned'));
        $response->assertCookie('trust_token');
        $this->assertTrue(BannedUser::isBanned('ip', '127.0.0.1'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function angry_cheater_scenario_new_account_with_banned_cookie()
    {
        $user1 = $this->makeUser('192.168.1.100');
        $this->actingAs($user1);
        $user1->ban('Cheating');
        
        $response = $this->get('/profile');
        $bannedCookie = $response->getCookie('trust_token')->getValue();
        
        $user2 = $this->makeUser('192.168.1.200');
        
        $response = $this->actingAs($user2)
            ->withCookie('trust_token', $bannedCookie)
            ->get('/profile');
        
        $response->assertRedirect(route('banned'));
        $this->assertTrue($user2->fresh()->is_banned);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function angry_cheater_scenario_vpn_hopping()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        
        $cookieValue = hash('sha256', 'persistent_cheater');
        BannedUser::banIdentifier('cookie', $cookieValue, 'Original ban');
        
        
        $response = $this->withCookie('trust_token', $cookieValue)->get('/profile');
        
        $response->assertRedirect(route('banned'));
        
        
        $this->assertEquals(1, BannedUser::where('type', 'cookie')->where('value', $cookieValue)->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function angry_cheater_scenario_multiple_browsers()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $user->ban('Multi-accounting');
        
        $response1 = $this->get('/profile');
        $cookie1 = $response1->getCookie('trust_token')->getValue();
        
        $response2 = $this->get('/profile');
        $cookie2 = $response2->getCookie('trust_token')->getValue();
        
        $response1->assertRedirect(route('banned'));
        $response2->assertRedirect(route('banned'));
        
        $this->assertNotNull($cookie1);
        $this->assertNotNull($cookie2);
        
        $this->assertTrue(BannedUser::isBanned('cookie', $cookie1));
        $this->assertTrue(BannedUser::isBanned('cookie', $cookie2));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function angry_cheater_scenario_device_switching()
    {
        $bannedCookie = hash('sha256', 'device_hopper');
        BannedUser::banIdentifier('cookie', $bannedCookie, 'Device hopping');
        
        for ($i = 1; $i <= 5; $i++) {
            $user = $this->makeUser("192.168.1.{$i}");
            
            $response = $this->actingAs($user)
                ->withCookie('trust_token', $bannedCookie)
                ->get('/profile');
            
            $response->assertRedirect(route('banned'));
            $this->assertTrue($user->fresh()->is_banned);
        }
        
        $this->assertEquals(1, BannedUser::where('type', 'cookie')->where('value', $bannedCookie)->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function non_banned_users_work_normally()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        
        $response = $this->get('/profile');
        
        
        $this->assertNotEquals(route('banned'), $response->headers->get('Location'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function logout_route_not_blocked_by_ban_check()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $user->ban('Test');
        
        $response = $this->post(route('logout'));
        
        $response->assertRedirect(route('login'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function banned_route_accessible_without_infinite_loop()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        
        BannedUser::banIdentifier('ip', '127.0.0.1', 'Test');
        
        $response = $this->get(route('banned'));
        
        
        $this->assertNotEquals(route('banned'), $response->headers->get('Location'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cookie_persists_for_two_years()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $user->ban('Long term ban');
        
        $response = $this->get('/profile');
        
        $cookie = $response->getCookie('trust_token');
        
        $this->assertEquals(1051200, $cookie->getMaxAge() / 60);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function banned_user_from_different_ip_bans_new_ip()
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $user->ban('Test ban');
        
        $response = $this->get('/profile');
        
        $cookie = $response->getCookie('trust_token')->getValue();
        
        
        $this->assertNotNull($cookie);
        $this->assertTrue(BannedUser::isBanned('cookie', $cookie));
        $this->assertTrue(BannedUser::isBanned('ip', '127.0.0.1'));
    }
}
