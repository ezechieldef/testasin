<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concours', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('libelle', 100);
            $table->date('date_epreuve');
            $table->timestamp('cloture');
        });
        Schema::create('centres', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 1)->unique();
            $table->string('nom', 50);
            $table->unsignedInteger('capacite');
        });
        Schema::create('candidats', function (Blueprint $table): void {
            $table->id();
            $table->string('npi', 10)->unique();
            $table->string('nom', 60);
            $table->string('prenoms', 80);
            $table->date('date_naissance');
            $table->timestamp('cree_le');
        });
        Schema::create('inscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('concours_id')->constrained('concours');
            $table->foreignId('centre_id')->constrained('centres');
            $table->string('npi', 10);
            $table->foreign('npi')->references('npi')->on('candidats');
            $table->string('numero_table', 32);
            $table->string('statut', 30);
            $table->timestamp('cree_le');
            $table->unique(['npi', 'concours_id']);
            $table->unique(['centre_id', 'numero_table']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscriptions');
        Schema::dropIfExists('candidats');
        Schema::dropIfExists('centres');
        Schema::dropIfExists('concours');
    }
};
