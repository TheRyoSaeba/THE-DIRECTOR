<?php

namespace App\Http\Controllers;

use App\Actions\Arrest;
use App\Actions\BankerLaunderClient;
use App\Actions\CommunityService;
use App\Actions\CorporateAudit;
use App\Actions\CryptoRugPull;
use App\Actions\Extortion;
use App\Actions\InvestmentFraud;
use App\Actions\Kidnapping;
use App\Actions\Launder;
use App\Actions\MedicineSale;
use App\Actions\MedicineProduction;
use App\Actions\NGRI;
use App\Actions\PlantBomb;
use App\Models\CharacterJournal;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActionController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return redirect()->route('character.create');
        }

        if (!$character->career) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Careers/Actions', [
            'actions' => fn() => $this->getActions($character),
        ]);
    }

    private function getActions($character): array
    {
        $character->loadMissing([
            'items.template',
            'timers',
            'career',
        ]);

        $careerCode = strtolower($character->career->code);

        if ($careerCode === 'corporation') {
            $character->loadMissing('corporation');

            if ($character->corporation) {
                if ((int) $character->corporation->home_city_id === (int) $character->city_id && $character->relationLoaded('city')) {
                    $character->corporation->setRelation('city', $character->city);
                } else {
                    $character->corporation->loadMissing('city');
                }
            }
        }

        $career = match ($careerCode) {
            'corporation' => [
                (new InvestmentFraud())->getShape($character),
                (new MedicineSale())->getShape($character),
            ],
            'police' => [
                (new Arrest())->getShape($character),
                (new CorporateAudit())->getShape($character),
            ],
            'healthcare' => [(new NGRI())->getShape($character)],
            default => [],
        };

        $general = [
            (new MedicineProduction())->getShape($character),
            (new CommunityService())->getShape($character),
            (new CryptoRugPull())->getShape($character),
            (new Extortion())->getShape($character),
            (new Kidnapping())->getShape($character),
            (new Launder())->getShape($character),
            (new PlantBomb())->getShape($character),
            (new BankerLaunderClient())->getShape($character),
        ];

        return array_merge($career, $general);
    }

    public function cryptoRugPull(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new CryptoRugPull())->execute($character, $request->all());
    }

    public function communityService(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new CommunityService())->execute($character, $request->all());
    }

    public function kidnapping(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new Kidnapping())->execute($character, $request->all());
    }

    public function cancelKidnapping(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new Kidnapping())->cancel($character);
    }

    public function acceptKidnapping(Request $request, CharacterJournal $journal)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            $journal->delete();
            return back()->with('error', 'No character found.');
        }

        return (new Kidnapping())->accept($character, $journal);
    }

    public function declineKidnapping(Request $request, CharacterJournal $journal)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            $journal->delete();
            return back()->with('error', 'No character found.');
        }

        return (new Kidnapping())->decline($character, $journal);
    }

    public function investmentFraud(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new InvestmentFraud())->execute($character, $request->all());
    }

    public function cancelInvestmentFraud(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new InvestmentFraud())->cancel($character);
    }

    public function medicalSale(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new MedicineSale())->execute($character, $request->all());
    }

    public function cancelMedicineSale(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new MedicineSale())->cancel($character, $request->all());
    }

    public function acceptMedicineSale(Request $request, CharacterJournal $journal)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            $journal->delete();
            return back()->with('error', 'No character found.');
        }

        return (new MedicineSale())->accept($character, $journal);
    }

    public function declineMedicineSale(Request $request, CharacterJournal $journal)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            $journal->delete();
            return back()->with('error', 'No character found.');
        }

        return (new MedicineSale())->decline($character, $journal);
    }

    public function acceptInvestmentFraud(Request $request, CharacterJournal $journal)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            $journal->delete();
            return back()->with('error', 'No character found.');
        }

        return (new InvestmentFraud())->accept($character, $journal);
    }

    public function declineInvestmentFraud(Request $request, CharacterJournal $journal)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            $journal->delete();
            return back()->with('error', 'No character found.');
        }

        return (new InvestmentFraud())->decline($character, $journal);
    }

    public function arrest(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new Arrest())->execute($character, $request->all());
    }

    public function corporateAudit(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new CorporateAudit())->execute($character, $request->all());
    }

    public function launder(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new Launder())->execute($character, $request->all());
    }

    public function ngri(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new NGRI())->execute($character, $request->all());
    }

    public function plantBomb(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new PlantBomb())->execute($character, $request->all());
    }

    public function extortion(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new Extortion())->execute($character, $request->all());
    }

    public function bankerLaunderClient(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        $character->loadMissing('timers');

        return (new BankerLaunderClient())->execute($character, $request->all());
    }

    public function bankerLaunderClientRefund(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        return (new BankerLaunderClient())->refund($character, $request->all());
    }
}
