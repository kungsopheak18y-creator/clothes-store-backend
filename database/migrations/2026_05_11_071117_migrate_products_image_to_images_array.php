<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('images')->nullable()->after('price');
        });

        // Migrate old single image into array
        DB::table('products')->whereNotNull('image')->get()->each(function ($product) {
            DB::table('products')->where('id', $product->id)->update([
                'images' => json_encode([$product->image]),
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image');
        });

        if (Schema::hasColumn('products', 'stock')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('stock');
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image')->nullable()->after('price');
            $table->integer('stock')->default(0);
            $table->dropColumn('images');
        });
    }
};