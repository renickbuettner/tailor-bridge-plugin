<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('renick_tailorcompanion_state', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('renick_tailorcompanion_state');
    }
};
