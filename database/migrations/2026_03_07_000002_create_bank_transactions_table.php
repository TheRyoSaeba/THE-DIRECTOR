<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        
        Schema::dropIfExists('bank_transactions');

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('character_id')
                ->constrained('characters')
                ->cascadeOnDelete();

            $table->enum('type', [
                'deposit',
                'withdraw',
                'transfer_sent',
                'transfer_received',
                'trade_win',
                'trade_loss',
            ]);

            $table->bigInteger('amount');                    
            $table->bigInteger('fee')->default(0);           
            $table->string('counterparty', 30)->nullable();  
            $table->string('note', 40)->nullable();
            $table->bigInteger('balance_after');             

            $table->timestamp('created_at')->useCurrent();

            // All reads are: WHERE character_id = ? ORDER BY id DESC LIMIT 30
            
            $table->index(['character_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
