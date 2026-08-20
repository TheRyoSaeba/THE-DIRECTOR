<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\City;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class CommunityService extends Action
{
    private const COOLDOWN_MINUTES = 15;

    private const TIERS = [
        [
            'id' => 'state_park',
            'min_exp' => 0,
            'label' => 'State Park',
            'xp' => 50,
            'crime_reduction' => 0.01,
            'message' => 'You spent the afternoon volunteering at the local state park cleaning up litter and rebuilding trails, wondering how much better the view would be if the whole thing was converted into a shopping centre.',
        ],
        [
            'id' => 'animal_shelter',
            'min_exp' => 5000,
            'label' => 'Animal Shelter',
            'xp' => 100,
            'crime_reduction' => 0.02,
            'message' => 'You spent the day cleaning out cages, feeding all the animals, and trying to convince hesitant people to adopt the pets so they don\'t end up euthanized. You feel good.',
        ],
        [
            'id' => 'neighborhood_watch',
            'min_exp' => 10000,
            'label' => 'Neighborhood Watch',
            'xp' => 150,
            'crime_reduction' => 0.05,
            'message' => "You spent all day on your phone glued to the neighborhood watch app,walking around the block and glaring at anyone who looked remotely suspicious. Somehow, you're sure you made a difference.",
        ],
    ];

    public function getId(): string
    {
        return 'community_service';
    }

    public function getShape(Character $character): array
    {
        $totalExp = (int) $character->total_character_exp;
        $tier = $this->currentTier($totalExp);

        $targets = array_map(function (array $candidate) use ($totalExp) {
            $locked = $totalExp < $candidate['min_exp'];

            return [
                'id' => $candidate['id'],
                'name' => $candidate['label'],
                'subtitle' => $locked ? 'Locked' : 'Unlocked',
            ];
        }, self::TIERS);

        return [
            'id' => $this->getId(),
            'title' => 'Community Service',
 
            'category' => 'Civic Responsibility',
            'description' => "Human beings are supposedly dependant rational animals, the kind that need community and society. Pick a cause you obviously believe in, help reduce city crime, and maybe you'll even get a little stronger in the process.",
            'image_url' => 'https://images.thedirector.app/actions/volunteer.jpg',
            'icon' => 'Users',
            'button_label' => 'Do Volunteer Work',
            'group_init_label' => null,
            'execute_route' => route('actions.community-service'),
            'cancel_route' => null,
            'is_group_action' => false,
            'available' => true,
            'blocker' => null,
            'is_waiting' => false,
            'is_ready' => false,
            'active_members' => null,
            'targets' => $targets,
            'accomplices' => null,
            'has_amount_input' => false,
            'amount_label' => null,
            'pick_label' => 'Choose a service',
            'target_icon' => 'user',
        ];
    }

    public function canExecute(Character $character): array
    {
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
        }

        if (!$this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You cannot perform community service from a hospital bed or a jail cell.'];
        }

        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        return DB::transaction(function () use ($character, $params) {

            $check = $this->canExecute($character);
            if (!$check['valid']) {
                return $this->error($check['error']);
            }

            $timer = DB::table('character_timers')
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->first(['id', 'next_action_at', 'hospital_until', 'jail_until']);

            if (!$timer) {
                return $this->error('No character found.');
            }

            $selectedId = (string) ($params['target_id'] ?? '');
            $tier = $this->findTier($selectedId);

            if (!$tier) {
                return $this->error('You must choose a service.');
            }

            if ((int) $character->total_character_exp < (int) $tier['min_exp']) {
                return $this->error($tier['label'] . ' is not available to you yet.');
            }

            DB::table('characters')
                ->where('id', $character->id)
                ->update([
                    'total_character_exp' => DB::raw('total_character_exp + ' . (int) $tier['xp']),
                ]);

            City::decreaseCrimeRateById($character->city_id, (float) $tier['crime_reduction']);

            $this->setCooldownMinutes($character, self::COOLDOWN_MINUTES);

            return $this->success($tier['message']);
        });
    }

    private function currentTier(int $totalCharacterExp): array
    {
        $tier = self::TIERS[0];

        foreach (self::TIERS as $candidate) {
            if ($totalCharacterExp >= $candidate['min_exp']) {
                $tier = $candidate;
            }
        }

        return $tier;
    }

    private function findTier(string $id): ?array
    {
        foreach (self::TIERS as $tier) {
            if ($tier['id'] === $id) {
                return $tier;
            }
        }

        return null;
    }
}
