<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Subscriptions Table
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['daily', 'monthly']);
            $table->integer('views_allowed');
            $table->integer('views_used')->default(0);
            $table->timestamp('expires_at');
            $table->enum('status', ['pending', 'active', 'expired'])->default('pending');
            $table->timestamps();
        });

        // 2. Payments Table
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('amount', 8, 2);
            $table->string('payment_method'); // 'vodafone_cash', 'instapay', etc.
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('paymob_order_id')->nullable()->index();
            $table->string('paymob_transaction_id')->nullable()->index();
            $table->timestamps();
        });

        // 3. Contacts Table (Tracks unlocked profiles)
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // The subscriber
            $table->foreignId('contacted_user_id')->constrained('users')->onDelete('cascade'); // The contacted profile
            $table->foreignId('subscription_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            // Prevent duplicate contacts within the database
            $table->unique(['user_id', 'contacted_user_id', 'subscription_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
    }
};
