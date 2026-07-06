<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('renick_tailorcompanion_journal', function (Blueprint $table) {
            // Auto-increment id doubles as the monotonic sync cursor.
            $table->increments('id');
            $table->string('blueprint_uuid', 36)->index();
            $table->integer('record_id')->unsigned();
            $table->string('action', 16);
            $table->integer('site_id')->unsigned()->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['blueprint_uuid', 'record_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('renick_tailorcompanion_journal');
    }
};
