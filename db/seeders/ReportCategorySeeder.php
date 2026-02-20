<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


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
