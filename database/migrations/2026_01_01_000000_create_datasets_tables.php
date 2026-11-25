<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('original_filename');
            // [{"key": "c1", "label": "Invoice No", "type": "number|text|date"}]
            $table->json('columns');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamps();
        });

        Schema::create('dataset_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            $table->json('data'); // {"c1": "...", "c2": 12.5}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_rows');
        Schema::dropIfExists('datasets');
    }
};
