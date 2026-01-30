<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade columna 'source' para distinguir documentos de la carpeta compartida (remote)
     * vs subidos localmente por la app (local). Permite listar y visualizar ambos sin duplicar.
     */
    public function up(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->string('source', 16)->default('remote')->after('path');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
