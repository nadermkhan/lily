<?php

use Lily\Database\Schema\Schema;
use Lily\Database\Schema\Blueprint;

return new class {
    public function up(Schema $schema): void
    {
        $schema->create('hoteyes_footprints', function (Blueprint $table) {
            $table->id('id');
            $table->string('ip_address');
            $table->string('user_id')->nullable();
            $table->integer('ram')->nullable();
            $table->integer('cpu_cores')->nullable();
            $table->string('resolution')->nullable();
            $table->string('connection_type')->nullable();
            $table->string('timezone')->nullable();
            $table->string('gpu_renderer')->nullable();
            $table->string('hardware_signature');
            $table->dateTime('created_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('hoteyes_footprints');
    }
};
