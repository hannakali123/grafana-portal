<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesSeeder extends Seeder
{
    public function run(): void
    {
        // 90 Tage rückwirkend je ein Tageswert
        $today = now()->startOfDay();
        foreach (range(0, 89) as $i) {
            DB::table('sales')->insert([
                'sale_date'  => $today->copy()->subDays($i),   // DATE reicht aus
                'amount'     => random_int(80, 400) + random_int(0, 99)/100,
                'category'   => ['Hardware','Software','Service'][array_rand([0,1,2])],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
