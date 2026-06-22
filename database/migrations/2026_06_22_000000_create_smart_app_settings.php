<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per app_id, plus a sentinel app_id=0 row for the global naming
 * template (same app_id=0 convention as smart_attribute_thresholds' global
 * default row). naming_template on the app_id=0 row is the installation-wide
 * default disk-naming template, shared by every device; disk_naming_templates
 * is a per-device JSON map of disk_key => template for per-disk overrides (a
 * disk only appears here when it has an explicit override — absent means it
 * inherits the global template, or the "$device" fallback if neither is
 * set). default_view_mode is the initial per-disk view mode
 * (Basic/Metadata/Self-test/Statistics/Graphs) used on the overview page
 * before the user picks one (which is then remembered client-side via
 * cookie, same as the label-mode selector) — this one stays per-device.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('smart_app_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id')->unique();
            $table->string('naming_template', 120)->nullable();
            $table->json('disk_naming_templates')->nullable();
            $table->string('default_view_mode', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_app_settings');
    }
};
