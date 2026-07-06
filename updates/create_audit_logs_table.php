<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('renick_tailorcompanion_audit_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('token_id')->unsigned()->nullable()->index();
            $table->integer('backend_user_id')->unsigned()->nullable()->index();
            $table->string('action', 40)->index();
            $table->string('blueprint_uuid', 36)->nullable()->index();
            $table->integer('record_id')->unsigned()->nullable();
            // Named `diff`, not `changes` — Eloquent has an internal protected
            // $changes property that shadows attribute access inside the class.
            $table->mediumText('diff')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('renick_tailorcompanion_audit_logs');
    }
};
