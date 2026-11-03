<?php

declare(strict_types=1);

namespace Internetztube\SlugEqualsTitle\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Internetztube\SlugEqualsTitle\Slugs;

/**
 * The table of remembered choices (Slugs::TABLE), as 3.1 left it (4.0 requires 3.1.0 first, so an update finds it and
 * runs nothing): one row per element that was saved from an edit form, gone with the element.
 */
class Install extends Migration
{
    public function up(): void
    {
        Schema::create(Slugs::TABLE, function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('elementId');
            $table->boolean('enabled');
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
            $table->unique(['elementId']);
            $table->foreign('elementId')->references('id')->on(Table::ELEMENTS)->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Slugs::TABLE);
    }
}
