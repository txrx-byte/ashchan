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
 * Report Category model - ported from OpenYotsuba report_categories manager.
 * 
 * Defines categories for classifying reports (e.g., "Gore", "Spam", "Child Safety").
 * Each category has a weight that contributes to report priority.
 * 
 * @property int        $id
 * @property string     $board             Board slug or empty for global (_ws_, _nws_, _all_)
 * @property string     $title             Category title
 * @property float      $weight            Weight/severity multiplier
 * @property string     $exclude_boards    Comma-separated list of boards to exclude
 * @property int        $filtered          Filter threshold (0=disabled, >0=abuse threshold)
 * @property int        $op_only           1=OP posts only, 0=any
 * @property int        $reply_only        1=Replies only, 0=any
 * @property int        $image_only        1=Posts with images only, 0=any
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @method static \Hyperf\Database\Model\Builder<ReportCategory> query()
 * @method static ReportCategory|null find(mixed $id)
 * @method static ReportCategory create(array<string, mixed> $attributes)
 */
class ReportCategory extends Model
{
    protected ?string $table = 'report_categories';

    /** @var array<int, string> */
    protected array $fillable = [
        'board',
        'title',
        'weight',
        'exclude_boards',
        'filtered',
        'op_only',
        'reply_only',
        'image_only',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'           => 'integer',
        'weight'       => 'float',
        'filtered'     => 'integer',
        'op_only'      => 'integer',
        'reply_only'   => 'integer',
        'image_only'   => 'integer',
    ];

    /**
     * Special board tags
     */
    public const WS_BOARD = '_ws_';      // Worksafe boards only
    public const NWS_BOARD = '_nws_';    // Not worksafe boards only
    public const ALL_BOARDS = '_all_';   // All boards

    /**
     * Weight constraints (from OpenYotsuba)
     */
    public const MAX_WEIGHT = 9999.99;

    /**
     * Get categories for a specific board
     * @param \Hyperf\Database\Model\Builder<ReportCategory> $query
     * @return \Hyperf\Database\Model\Builder<ReportCategory>
     */
    public function scopeForBoard(
        \Hyperf\Database\Model\Builder $query,
        string $board
    ): \Hyperf\Database\Model\Builder {
        return $query->where(function (\Hyperf\Database\Model\Builder $q) use ($board): void {
            $q->where('board', '')
              ->orWhere('board', $board)
              ->orWhere('board', self::ALL_BOARDS);
        });
    }

    /**
     * Get worksafe categories only
     * @param \Hyperf\Database\Model\Builder<ReportCategory> $query
     * @return \Hyperf\Database\Model\Builder<ReportCategory>
     */
    public function scopeWorksafe(\Hyperf\Database\Model\Builder $query): \Hyperf\Database\Model\Builder
    {
        return $query->where(function (\Hyperf\Database\Model\Builder $q): void {
            $q->where('board', '')
              ->orWhere('board', self::WS_BOARD);
        });
    }

    /**
     * Get not-worksafe categories only
     * @param \Hyperf\Database\Model\Builder<ReportCategory> $query
     * @return \Hyperf\Database\Model\Builder<ReportCategory>
     */
    public function scopeNotWorksafe(\Hyperf\Database\Model\Builder $query): \Hyperf\Database\Model\Builder
    {
        return $query->where(function (\Hyperf\Database\Model\Builder $q): void {
            $q->where('board', '')
              ->orWhere('board', self::NWS_BOARD);
        });
    }

    /**
     * Check if category applies to a post
     */
    public function appliesToPost(
        string $board,
        bool $isOp,
        bool $hasImage
    ): bool {
        // Check board exclusions
        $excludeBoards = $this->getAttribute('exclude_boards');
        if (is_string($excludeBoards) && $excludeBoards !== '') {
            $excluded = explode(',', $excludeBoards);
            if (in_array($board, $excluded, true)) {
                return false;
            }
        }

        // Check board scope
        $categoryBoard = $this->getAttribute('board');
        if ($categoryBoard !== '' && $categoryBoard !== self::ALL_BOARDS) {
            if ($categoryBoard === self::WS_BOARD && !$this->isWorksafeBoard($board)) {
                return false;
            }
            if ($categoryBoard === self::NWS_BOARD && $this->isWorksafeBoard($board)) {
                return false;
            }
            if ($categoryBoard !== $board
                && $categoryBoard !== self::WS_BOARD
                && $categoryBoard !== self::NWS_BOARD
            ) {
                return false;
            }
        }

        // Check post type restrictions
        /** @var mixed $opOnly */
        $opOnly = $this->getAttribute('op_only');
        /** @var mixed $replyOnly */
        $replyOnly = $this->getAttribute('reply_only');
        /** @var mixed $imageOnly */
        $imageOnly = $this->getAttribute('image_only');
        if ((int) $opOnly === 1 && !$isOp) {
            return false;
        }
        if ((int) $replyOnly === 1 && $isOp) {
            return false;
        }
        if ((int) $imageOnly === 1 && !$hasImage) {
            return false;
        }

        return true;
    }

    /**
     * Check if a board is worksafe
     * This should be configured based on your board list
     */
    private function isWorksafeBoard(string $board): bool
    {
        // Common worksafe boards - customize based on your board list
        $worksafe = ['g', 'prog', 'fit', 'sci', 'biz', 'diy', 'ck', 'gd', 'ic', 'lit'];
        return in_array($board, $worksafe, true);
    }

    /**
     * Get all categories organized for a report form
     * @return array{rule: array<int, array<string, mixed>>, illegal: array<string, mixed>|null}
     */
    public static function getForReportForm(string $board, bool $isWorksafe): array
    {
        $categories = self::query()
            ->orderBy('board', 'desc') // Board-specific first
            ->orderBy('weight', 'desc')
            ->get();

        $result = [
            'rule' => [],
            'illegal' => null,
        ];

        foreach ($categories as $cat) {
            /** @var ReportCategory $cat */
            /** @var array<string, mixed> $catArray */
            $catArray = $cat->toArray();

            // Illegal category (ID 31 is traditional)
            /** @var mixed $catId */
            $catId = $cat->getAttribute('id');
            if ((int) $catId === 31) {
                $result['illegal'] = $catArray;
                continue;
            }

            // Check if category applies
            /** @var mixed $catBoard */
            $catBoard = $cat->getAttribute('board');
            if ($catBoard === self::WS_BOARD && !$isWorksafe) {
                continue;
            }
            if ($catBoard === self::NWS_BOARD && $isWorksafe) {
                continue;
            }

            $result['rule'][] = $catArray;
        }

        /** @var array{rule: array<int, array<string, mixed>>, illegal: array<string, mixed>|null} $result */
        return $result;
    }
}
