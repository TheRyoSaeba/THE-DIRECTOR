<?php

namespace App\Services;

use App\Models\User;
use App\Support\UserSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserService
{
    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            return $user;
        });
    }

    public function banUser(User $user, ?string $reason = null, ?\DateTimeInterface $until = null): void
    {
        $user->ban($reason, $until);

        $character = $user->character()->withTrashed()->first();
        if ($character && ! $character->trashed()) {
            $character->kill('banned', $reason);
        }

        $bannedBy = auth()->id();

        if ($user->last_ip) {
            \App\Models\BannedUser::banIdentifier('ip', $user->last_ip, "Banned user: {$user->username}", $bannedBy, $until, $user->id);
        }

        // Ban every IP this user has recently been seen from (presence IP
        // history, 30 days; plus live session rows under the database driver),
        // so a temp/perma ban also blocks their known connections. Each row is
        // tagged with the user_id so unban can clear exactly these.
        $sessionIps = UserSessions::knownIps($user->id);

        foreach ($sessionIps as $ip) {
            \App\Models\BannedUser::banIdentifier('ip', $ip, "Banned user: {$user->username}", $bannedBy, $until, $user->id);
        }

        //$this->clearUserSessions($user);

        $this->logBanAction($user, $reason);
    }

    private function clearUserSessions(User $user): void
    {
        UserSessions::logoutEverywhere($user);
    }

    private function logBanAction(User $user, ?string $reason): void
    {
        Log::info("User banned", [
            'user_id' => $user->id,
            'username' => $user->username,
            'reason' => $reason,
        ]);
    }

    public function unbanUser(User $user): void
    {
        $user->unban();

        // Lift the identifier (IP/cookie) bans created for this user. Without
        // this the account is unbanned but the next request re-trips the
        // identifier check and silently re-bans them.
        \App\Models\BannedUser::clearForUser($user->id);

        $this->logUnbanAction($user);
    }

    private function logUnbanAction(User $user): void
    {
        Log::info("User unbanned", [
            'user_id' => $user->id,
            'username' => $user->username,
        ]);
    }
}
