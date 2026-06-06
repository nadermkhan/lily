<?php

use Lily\Database\Schema\Schema;
use Lily\Database\Schema\Blueprint;

return new class {
    public function up(Schema $schema): void
    {
        $schema->create('personal_access_tokens', function (Blueprint $table) {
            $table->id('id');
            $table->string('user_id');
            $table->string('name');
            $table->string('token');
            $table->dateTime('created_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('personal_access_tokens');
    }
};
