<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('corporation_properties', function (Blueprint $table) {
            $table->id();

            // NULL corporation_id rows are seeded purchase templates.
            // Owned property rows set corporation_id.
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->foreign('corporation_id')
                ->references('id')->on('corporations')
                ->onDelete('cascade');

            // hq | medical | subsidiary | investment
            $table->string('type', 20);

            // HQ tier determines member slots through corporations.hq_tier.
            $table->unsignedTinyInteger('tier')->default(1);
            $table->string('name', 100);
            $table->string('image_url')->nullable();
            $table->unsignedBigInteger('price')->default(0);
            $table->string('condition', 20)->default('CONSTRUCTED');
            $table->timestampTz('seized_until')->nullable();
            $table->timestampTz('protection_until')->nullable();
            $table->timestampTz('last_upkeep_at')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampsTz();

            // One owned property per type per corporation. Template rows have NULL corporation_id.
            $table->unique(['corporation_id', 'type']);
            $table->index(['type', 'tier']);
        });

        DB::table('corporation_properties')->insert([
            //! HQ
            [
                'corporation_id' => null,
                'type' => 'hq',
                'tier' => 1,
                'name' => 'Clemens Street. Headquarters',
                'image_url' => 'https://images.thedirector.app/properties/hq1.jpg',
                'price' => 1_000_000,
                'condition' => 'CONSTRUCTED',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'corporation_id' => null,
                'type' => 'hq',
                'tier' => 2,
                'name' => 'Seung-gi Park World Headquarters',
                'image_url' => 'https://images.thedirector.app/properties/hq2.jpg',
                'price' => 2_500_000,
                'condition' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'corporation_id' => null,
                'type' => 'hq',
                'tier' => 3,
                'name' => 'Baruch Avenue Headquarters',
                'image_url' => 'https://images.thedirector.app/properties/hq3.jpg',
                'price' => 5_000_000,
                'condition' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            //! Medical FOR PRODUCING ITEMS
            [
                'corporation_id' => null,
                'type' => 'medical',
                'tier' => 1,
                'name' => 'Turing Pharmaceuticals',
                'image_url' => 'https://images.thedirector.app/properties/medical1.jpg',
                'price' => 500_000,
                'condition' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            //!for corp laundering
            [
                'corporation_id' => null,
                'type' => 'laundering',
                'tier' => 1,
                'name' => 'PanamaCo Offshore Trust',
                'image_url' => 'https://images.thedirector.app/properties/laundering.png',
                'price' => 500_000,
                'condition' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_properties');
    }
};
