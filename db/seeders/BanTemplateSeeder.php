<?php
declare(strict_types=1);

use Hyperf\Database\Seeders\Seeder;
use Hyperf\DbConnection\Db;

/**
 * Seeder for default ban templates - EXACT port from OpenYotsuba setup.php
 * 
 * Source: /home/abrookstgz/OpenYotsuba/board/setup.php:168-176
 * 
 * These are the canonical 4chan ban templates used in production.
 */
return new class extends Seeder
{
    public function run(): void
    {
        // From OpenYotsuba setup.php lines 168-176
        // These are the EXACT ban templates used on 4chan
        $templates = [
            [
                // Line 168: Child Pornography (Explicit Image)
                'name' => 'Child Pornography (Explicit Image)',
                'rule' => 'global1',
                'ban_type' => 'zonly',
                'ban_days' => -1,
                'banlen' => 'indefinite',
                'public_reason' => 'Child pornography',
                'private_reason' => 'Child pornography',
                'publicban' => 0,
                'can_warn' => 0,
                'is_public' => 1,
                'save_type' => '',
                'action' => 'quarantine',
                'blacklist_image' => 1,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '__nofile__',
                'appealable' => 0,
                'active' => 1,
            ],
            [
                // Line 169: Child Pornography (Non-Explicit Image)
                'name' => 'Child Pornography (Non-Explicit Image)',
                'rule' => 'global1',
                'ban_type' => 'zonly',
                'ban_days' => -1,
                'banlen' => 'indefinite',
                'public_reason' => 'Child pornography',
                'private_reason' => 'Child pornography',
                'publicban' => 0,
                'can_warn' => 0,
                'is_public' => 1,
                'save_type' => '',
                'action' => 'revokepass_illegal',
                'blacklist_image' => 1,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '__nofile__',
                'appealable' => 0,
                'active' => 1,
            ],
            [
                // Line 170: Child Pornography (Links)
                'name' => 'Child Pornography (Links)',
                'rule' => 'global1',
                'ban_type' => 'zonly',
                'ban_days' => -1,
                'banlen' => 'indefinite',
                'public_reason' => 'Child pornography',
                'private_reason' => 'Child pornography',
                'publicban' => 0,
                'can_warn' => 0,
                'is_public' => 1,
                'save_type' => '',
                'action' => 'revokepass_illegal',
                'blacklist_image' => 0,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '',
                'appealable' => 0,
                'active' => 1,
            ],
            [
                // Line 171: Illegal content
                'name' => 'Illegal content',
                'rule' => 'global1',
                'ban_type' => 'zonly',
                'ban_days' => -1,
                'banlen' => 'indefinite',
                'public_reason' => 'You will not upload, post, discuss, request, or link to anything that violates applicable law.',
                'private_reason' => 'Illegal content',
                'publicban' => 0,
                'can_warn' => 0,
                'is_public' => 1,
                'save_type' => '',
                'action' => 'revokepass_illegal',
                'blacklist_image' => 0,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '',
                'appealable' => 0,
                'active' => 1,
            ],
            [
                // Line 172: NSFW on blue board
                'name' => 'NSFW on blue board',
                'rule' => 'global2',
                'ban_type' => 'global',
                'ban_days' => 1,
                'banlen' => '',
                'public_reason' => 'All boards with the Yotsuba B style as the default are to be considered "work safe". Violators may be temporarily banned and their posts removed. Note: Spoilered pornography or other "not safe for work" content is NOT allowed.',
                'private_reason' => 'NSFW on blue board',
                'publicban' => 1,
                'can_warn' => 1,
                'is_public' => 1,
                'save_type' => 'everything',
                'action' => 'delfile',
                'blacklist_image' => 0,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '__nws__',
                'appealable' => 1,
                'active' => 1,
            ],
            [
                // Line 173: False reports
                'name' => 'False reports',
                'rule' => 'global3',
                'ban_type' => 'global',
                'ban_days' => 0,
                'banlen' => '',
                'public_reason' => 'Submitting false or misclassified reports, or otherwise abusing the reporting system may result in a ban.',
                'private_reason' => 'False reports',
                'publicban' => 0,
                'can_warn' => 1,
                'is_public' => 0,
                'save_type' => '',
                'action' => '',
                'blacklist_image' => 0,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '',
                'appealable' => 1,
                'active' => 1,
            ],
            [
                // Line 174: Ban evasion
                'name' => 'Ban evasion',
                'rule' => 'global4',
                'ban_type' => 'global',
                'ban_days' => -1,
                'banlen' => 'indefinite',
                'public_reason' => 'Evading your ban will result in a permanent one. Instead, wait and appeal it!',
                'private_reason' => 'Ban evasion',
                'publicban' => 1,
                'can_warn' => 1,
                'is_public' => 1,
                'save_type' => 'everything',
                'action' => '',
                'blacklist_image' => 0,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '',
                'appealable' => 1,
                'active' => 1,
            ],
            [
                // Line 175: Spam
                'name' => 'Spam',
                'rule' => 'global5',
                'ban_type' => 'global',
                'ban_days' => 1,
                'banlen' => '',
                'public_reason' => 'No spamming or flooding of any kind. No intentionally evading spam or post filters.',
                'private_reason' => 'Spam',
                'publicban' => 0,
                'can_warn' => 1,
                'is_public' => 1,
                'save_type' => 'everything',
                'action' => 'delall',
                'blacklist_image' => 0,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '',
                'appealable' => 1,
                'active' => 1,
            ],
            [
                // Line 176: Advertising
                'name' => 'Advertising',
                'rule' => 'global6',
                'ban_type' => 'global',
                'ban_days' => 1,
                'banlen' => '',
                'public_reason' => 'Advertising (all forms) is not welcome—this includes any type of referral linking, "offers", soliciting, begging, stream threads, etc.',
                'private_reason' => 'Advertising',
                'publicban' => 0,
                'can_warn' => 1,
                'is_public' => 0,
                'save_type' => '',
                'action' => 'delall',
                'blacklist_image' => 0,
                'reject_image' => 0,
                'access' => 'janitor',
                'boards' => '',
                'exclude' => '',
                'appealable' => 1,
                'active' => 1,
            ],
        ];

        foreach ($templates as $tpl) {
            // Check if exists by name
            $existing = Db::table('ban_templates')
                ->where('name', $tpl['name'])
                ->first();

            if (!$existing) {
                Db::table('ban_templates')->insert([
                    ...$tpl,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
