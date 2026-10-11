<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_connections', function (Blueprint $table): void {
            $table->id();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('username')->nullable();
            $table->string('name')->nullable();
            $table->string('picture', 2048)->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_connections');
    }
};
