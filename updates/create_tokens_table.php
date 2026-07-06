<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('renick_tailorcompanion_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->integer('backend_user_id')->unsigned()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('renick_tailorcompanion_tokens');
    }
};
