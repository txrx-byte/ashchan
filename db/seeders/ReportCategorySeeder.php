<?php
declare(strict_types=1);

use Hyperf\Database\Seeders\Seeder;
use Hyperf\DbConnection\Db;

/**
 * Seeder for default report categories - EXACT port from OpenYotsuba setup.php
 * 
 * Source: /home/abrookstgz/OpenYotsuba/board/setup.php:157
 */
return new class extends Seeder
{
    public function run(): void
    {
        // From OpenYotsuba setup.php line 157:
        // mysql_global_call("insert into report_categories (title, id) values (\"This post violates applicable law.\", 31);");
        
        $categories = [
            // ID 31 is the canonical "Illegal" category from 4chan
            [
                'id' => 31,
                'board' => '',
                'title' => 'This post violates applicable law.',
                'weight' => 1000.00,
                'exclude_boards' => '',
                'filtered' => 0,
                'op_only' => 0,
                'reply_only' => 0,
                'image_only' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($categories as $cat) {
            $id = $cat['id'];
            unset($cat['id']);
            
            // Check if exists
            $existing = Db::table('report_categories')
                ->where('title', 'This post violates applicable law.')
                ->first();

            if (!$existing) {
                Db::table('report_categories')->insert($cat);
            }
        }
    }
};
