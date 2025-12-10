<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('chunks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('document_id')->constrained()->onDelete('cascade');
        $table->integer('chunk_index')->default(0);
        $table->text('text'); // chunk text
        $table->json('embedding')->nullable(); // store float array as JSON
        $table->float('similarity')->nullable(); // optional for caching
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
