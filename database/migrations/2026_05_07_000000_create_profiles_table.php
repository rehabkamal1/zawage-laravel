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
        Schema::dropIfExists('profiles');
        
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Basic Info
            $table->string('full_name')->nullable();
            $table->string('nickname')->nullable();
            $table->string('phone')->nullable();
            $table->date('dob')->nullable();
            $table->string('marital_status')->nullable();
            
            // Physical Info
            $table->string('skin_tone')->nullable();
            $table->integer('weight')->nullable();
            $table->integer('height')->nullable();
            
            // Location
            $table->string('governorate')->nullable();
            $table->string('area')->nullable();
            $table->text('address')->nullable();
            
            // Education & Religion
            $table->string('education')->nullable();
            $table->string('job')->nullable();
            $table->string('income')->nullable();
            $table->string('accommodation')->nullable();
            $table->string('prayer')->nullable();
            $table->string('hijab')->nullable(); // For females
            $table->string('smoking')->nullable();
            
            // Family & Questions
            $table->boolean('has_children')->default(false);
            $table->string('custody')->nullable();
            $table->integer('children_count')->default(0);
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('relation')->nullable();
            
            // Preferences
            $table->boolean('move_other_gov')->default(false);
            $table->boolean('family_house')->default(false);
            $table->string('dowry_status')->nullable();
            $table->boolean('accept_polygamy')->default(false);
            $table->text('diseases')->nullable();
            $table->text('bio')->nullable();
            
            // Detailed Requirements (For Matching)
            $table->string('req_governorate')->nullable();
            $table->integer('req_age_min')->nullable();
            $table->integer('req_age_max')->nullable();
            $table->string('req_marital_status')->nullable();
            $table->string('req_hijab')->nullable();
            $table->string('req_weight')->nullable();
            $table->string('req_height')->nullable();
            $table->string('req_prayer')->nullable();
            $table->string('req_move_other_gov')->nullable();
            $table->string('req_has_children')->nullable();
            $table->integer('req_children_count')->nullable();
            $table->string('req_guardian_phone')->nullable();
            $table->string('req_job')->nullable();
            $table->string('req_education')->nullable();
            $table->string('req_smoking')->nullable();
            $table->string('req_accept_polygamy')->nullable();
            
            $table->string('partner_status')->nullable();
            $table->text('partner_specs')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
