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
Schema::create('pdf_documents', function (Blueprint $table) {
  $table->id();
  $table->string('name');
  $table->string('path');       // p.ej. "2025/07/01/1174621.pdf"
  $table->unsignedSmallInteger('year');
  $table->unsignedTinyInteger('month');
  $table->unsignedTinyInteger('day');
  $table->timestamps();

  $table->index(['year','month','day']);
  $table->index('name');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_documents');
    }
};
