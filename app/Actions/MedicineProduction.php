<?php

namespace App\Actions;

use App\Http\Controllers\CorporationProfitController;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\CorporationProperty;
use Illuminate\Http\RedirectResponse;

class MedicineProduction extends Action
{
    public function getId(): string
    {
        return 'medicine_production';
    }

    public function getShape(Character $character): array
    {
        $targets = $this->targets($character);

        return [
            'id' => $this->getId(),
            'title' => 'Medicine Production',
         
            'category' => 'CORPORATE WORK',
            'description' => 'Use your medical expertise to engage in some off the books lab work for a corporation.',
            'image_url' => 'https://images.thedirector.app/properties/medical1.jpg',
            'icon' => 'PillIcon',
            'button_label' => 'Produce Batch',
            'group_init_label' => null,
            'execute_route' => route('actions.corporation.medical.produce'),
            'cancel_route' => null,
            'is_group_action' => false,
            'available' => $targets->isNotEmpty(),
            'blocker' => $targets->isEmpty() ? 'No corporation with a medical building is based in this city.' : null,
            'is_waiting' => false,
            'is_ready' => false,
            'active_members' => null,
            'targets' => $targets->values()->all(),
            'accomplices' => null,
            'has_amount_input' => false,
            'amount_label' => null,
            'pick_label' => 'Choose a company',
            'target_icon' => 'store',
        ];
    }

    public function canExecute(Character $character): array
    {
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
        }

        if (!$this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You cannot work a lab from a hospital bed or a jail cell.'];
        }

        $degree = $character->getDegree('medicine');
        if (!$degree || empty($degree['completed_at'])) {
            return ['valid' => false, 'error' => 'You need a completed medical degree.'];
        }

        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        return app(CorporationProfitController::class)->produceMedical(request());
    }

    private function targets(Character $character)
    {
        return Corporation::query()
            ->select('id', 'name', 'home_city_id', 'slush_fund')
            ->where('home_city_id', $character->city_id)
            ->operating()
            ->whereHas('medicalProperties')
            ->with([
                'city:id,name',
                'medicalProperties:id,corporation_id,type,data',
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Corporation $corp) {
                $medical = $corp->medicalProperties->first();
                $data = CorporationProperty::normalizeMedicalData($medical?->data);

                return [
                    'id' => $corp->id,
                    'name' => $corp->name,
                    'subtitle' => $corp->city?->name ?? 'Home city medical building',
                    'payout_per_pack' => (int) $data['medical_payout_per_pack'],
                ];
            });
    }
}
