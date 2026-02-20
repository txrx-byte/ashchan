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


namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * Report model - ported from OpenYotsuba ReportQueue system.
 * 
 * Represents a user-submitted report for a post/thread.
 * 
 * @property int        $id
 * @property string     $ip                Reporter IP (encrypted)
 * @property string|null $pwd              Reporter password hash
 * @property string|null $pass_id          4chan Pass ID
 * @property string     $board             Board slug (e.g., 'g', 'a')
 * @property int        $no                Post number
 * @property int        $resto             Thread number (0 if OP)
 * @property int        $cat               Category type (1=rule, 2=illegal)
 * @property float      $weight            Report weight/severity
 * @property int        $report_category   Specific category ID
 * @property string     $post_ip           Post author IP
 * @property string     $post_json         JSON snapshot of the post
 * @property int        $cleared           0=pending, 1=cleared
 * @property string     $cleared_by        Staff who cleared
 * @property string|null $req_sig          Request signature for spam filtering
 * @property int        $ws                Worksafe flag (1=ws, 0=nws)
 * @property \Carbon\Carbon $ts            Timestamp
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @method static \Hyperf\Database\Model\Builder<Report> query()
 * @method static Report|null find(mixed $id)
 * @method static Report findOrFail(mixed $id)
 * @method static Report create(array<string, mixed> $attributes)
 * @method static int count(array<string, mixed> $where = [])
 */
class Report extends Model
{
    protected ?string $table = 'reports';

    /** @var array<int, string> */
    protected array $fillable = [
        'ip',
        'pwd',
        'pass_id',
        'board',
        'no',
        'resto',
        'cat',
        'weight',
        'report_category',
        'post_ip',
        'post_json',
        'cleared',
        'cleared_by',
        'req_sig',
        'ws',
        'ts',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'              => 'integer',
        'no'              => 'integer',
        'resto'           => 'integer',
        'cat'             => 'integer',
        'weight'          => 'float',
        'report_category' => 'integer',
        'cleared'         => 'integer',
        'ws'              => 'integer',
        'ts'              => 'datetime',
    ];

    protected string $createdAtColumn = 'created_at';
    protected string $updatedAtColumn = 'updated_at';

    /**
     * Report categories
     */
    public const CAT_RULE = 1;
    public const CAT_ILLEGAL = 2;

    /**
     * Weight thresholds (from OpenYotsuba)
     */
    public const GLOBAL_THRES = 1500;      // Weight after which report is globally unlocked
    public const HIGHLIGHT_THRES = 500;    // Weight for highlighting
    public const THREAD_WEIGHT_BOOST = 1.25; // Weight multiplier for threads

    /**
     * Get reports for a specific board
     * @param \Hyperf\Database\Model\Builder<Report> $query
     * @return \Hyperf\Database\Model\Builder<Report>
     */
    public function scopeForBoard(
        \Hyperf\Database\Model\Builder $query,
        string $board
    ): \Hyperf\Database\Model\Builder {
        return $query->where('board', $board);
    }

    /**
     * Get only cleared reports
     * @param \Hyperf\Database\Model\Builder<Report> $query
     * @return \Hyperf\Database\Model\Builder<Report>
     */
    public function scopeCleared(\Hyperf\Database\Model\Builder $query): \Hyperf\Database\Model\Builder
    {
        return $query->where('cleared', 1);
    }

    /**
     * Get only pending reports
     * @param \Hyperf\Database\Model\Builder<Report> $query
     * @return \Hyperf\Database\Model\Builder<Report>
     */
    public function scopePending(\Hyperf\Database\Model\Builder $query): \Hyperf\Database\Model\Builder
    {
        return $query->where('cleared', 0);
    }

    /**
     * Get reports grouped by post with aggregated weight
     * @param \Hyperf\Database\Model\Builder<Report> $query
     * @return \Hyperf\Database\Query\Builder
     */
    public function scopeGroupedByPost(
        \Hyperf\Database\Model\Builder $query,
        ?string $board = null
    ): \Hyperf\Database\Query\Builder {
        $raw = $query->selectRaw('
            no,
            board,
            SUM(weight) as total_weight,
            COUNT(*) as cnt,
            GROUP_CONCAT(DISTINCT report_category) as cats,
            MAX(ts) as `time`,
            ANY_VALUE(id) as id,
            ANY_VALUE(post_json) as post_json,
            ANY_VALUE(resto) as resto,
            ANY_VALUE(post_ip) as post_ip,
            ANY_VALUE(ws) as ws,
            ANY_VALUE(cleared_by) as cleared_by
        ')
        ->groupBy('no', 'board');

        if ($board !== null) {
            $raw->where('board', $board);
        }

        return $raw->toBase();
    }

    /**
     * Check if a report is unlocked (high weight)
     */
    public function isUnlocked(): bool
    {
        /** @var mixed $weight */
        $weight = $this->getAttribute('total_weight');
        return (float) $weight >= self::GLOBAL_THRES;
    }

    /**
     * Get the post data as array
     * @return array<string, mixed>
     */
    public function getPostData(): array
    {
        $json = $this->getAttribute('post_json');
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            /** @var array<string, mixed>|null $decoded */
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Get the reporter's IP (decrypted if needed)
     */
    public function getReporterIp(): string
    {
        // In production, this should decrypt the IP
        $ip = $this->getAttribute('ip');
        return is_string($ip) ? $ip : '';
    }

    /**
     * Get report category name
     */
    public function getCategoryName(): string
    {
        /** @var mixed $rawId */
        $rawId = $this->getAttribute('report_category');
        $categoryId = (int) $rawId;
        $category = ReportCategory::find($categoryId);
        if (!$category instanceof ReportCategory) {
            return 'Unknown';
        }
        $title = $category->getAttribute('title');
        return is_string($title) ? $title : 'Unknown';
    }
}
