<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * Ban Template model - ported from OpenYotsuba ban_templates manager.
 * 
 * Predefined ban templates for common violations (spam, illegal content, etc.).
 * Templates standardize ban reasons, lengths, and actions.
 * 
 * @property int        $id
 * @property string     $rule              Rule ID (e.g., 'global1', 'global2', etc.)
 * @property string     $name              Template name
 * @property string     $ban_type          local|global|zonly
 * @property int        $ban_days          Ban duration in days (0=warn, -1=permanent)
 * @property string     $banlen            Ban length string (e.g., 'indefinite')
 * @property int        $can_warn          1=can issue warning instead
 * @property int        $publicban         1=public ban message
 * @property int        $is_public         1=display on ban page
 * @property string     $public_reason     Public reason shown to user
 * @property string     $private_reason    Internal reason (staff only)
 * @property string     $action            Action type (quarantine|revokepass_illegal|delfile|delall|etc)
 * @property string     $save_type         everything|json_only|''
 * @property int        $blacklist_image   1=add to image blacklist
 * @property int        $reject_image      1=reject image uploads
 * @property string     $access            Required access level (janitor|mod|manager|admin)
 * @property string     $boards            Comma-separated board list
 * @property string     $exclude           Exclude pattern (__nofile__|__nws__|'')
 * @property int        $appealable        1=can appeal
 * @property int        $active            1=active template
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @method static \Hyperf\Database\Model\Builder<BanTemplate> query()
 * @method static BanTemplate|null find(mixed $id)
 * @method static BanTemplate create(array<string, mixed> $attributes)
 */
class BanTemplate extends Model
{
    protected ?string $table = 'ban_templates';

    /** @var array<int, string> */
    protected array $fillable = [
        'rule',
        'name',
        'ban_type',
        'ban_days',
        'banlen',
        'can_warn',
        'publicban',
        'is_public',
        'public_reason',
        'private_reason',
        'action',
        'save_type',
        'blacklist_image',
        'reject_image',
        'access',
        'boards',
        'exclude',
        'appealable',
        'active',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'            => 'integer',
        'ban_days'      => 'integer',
        'can_warn'      => 'integer',
        'publicban'     => 'integer',
        'is_public'     => 'integer',
        'blacklist_image' => 'integer',
        'reject_image'  => 'integer',
        'appealable'    => 'integer',
        'active'        => 'integer',
    ];

    /**
     * Ban types
     */
    public const TYPE_LOCAL = 'local';
    public const TYPE_GLOBAL = 'global';
    public const TYPE_ZONLY = 'zonly'; // Unappealable

    /**
     * Special template IDs (from OpenYotsuba)
     */
    public const REPORT_ABUSE_TEMPLATE = 190;

    /**
     * Access levels
     */
    public const ACCESS_JANITOR = 'janitor';
    public const ACCESS_MOD = 'mod';
    public const ACCESS_MANAGER = 'manager';
    public const ACCESS_ADMIN = 'admin';

    /**
     * Get active templates
     *
     * @param \Hyperf\Database\Model\Builder<static> $query
     * @return \Hyperf\Database\Model\Builder<static>
     */
    public function scopeActive(\Hyperf\Database\Model\Builder $query): \Hyperf\Database\Model\Builder
    {
        return $query->where('active', 1);
    }

    /**
     * Get templates for a specific board
     *
     * @param \Hyperf\Database\Model\Builder<static> $query
     * @return \Hyperf\Database\Model\Builder<static>
     */
    public function scopeForBoard(
        \Hyperf\Database\Model\Builder $query,
        string $board
    ): \Hyperf\Database\Model\Builder {
        return $query->where(function ($q) use ($board) {
            $q->where('boards', '')
              ->orWhere('boards', 'like', "%{$board}%");
        });
    }

    /**
     * Get templates by access level
     *
     * @param \Hyperf\Database\Model\Builder<static> $query
     * @return \Hyperf\Database\Model\Builder<static>
     */
    public function scopeForAccess(
        \Hyperf\Database\Model\Builder $query,
        string $access
    ): \Hyperf\Database\Model\Builder {
        $accessLevels = [
            self::ACCESS_JANITOR => 1,
            self::ACCESS_MOD => 2,
            self::ACCESS_MANAGER => 3,
            self::ACCESS_ADMIN => 4,
        ];

        $requiredLevel = $accessLevels[$access] ?? 1;

        $allowedAccessLevels = [];
        foreach ($accessLevels as $levelName => $levelValue) {
            if ($levelValue >= $requiredLevel) {
                $allowedAccessLevels[] = $levelName;
            }
        }

        return $query->where(function ($q) use ($access, $allowedAccessLevels) {
            $q->where('access', $access)
              ->orWhereIn('access', $allowedAccessLevels);
        });
    }

    /**
     * Check if template is a warning (not a ban)
     */
    public function isWarning(): bool
    {
        $banDays = (int) $this->getAttribute('ban_days');
        return $banDays === 0;
    }

    /**
     * Check if template is permanent ban
     */
    public function isPermanent(): bool
    {
        $banDays = (int) $this->getAttribute('ban_days');
        return $banDays === -1;
    }

    /**
     * Get ban length in seconds
     */
    public function getBanLengthSeconds(): int
    {
        $banDays = (int) $this->getAttribute('ban_days');

        if ($banDays <= 0) {
            return 0; // Warning or permanent
        }

        return $banDays * 86400;
    }

    /**
     * Get all active templates grouped by type
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function getGroupedTemplates(): array
    {
        $templates = self::query()
            ->where('active', 1)
            ->orderBy('rule')
            ->orderBy('name')
            ->get();

        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [
            'local' => [],
            'global' => [],
            'zonly' => [],
        ];

        foreach ($templates as $tpl) {
            $type = (string) $tpl->getAttribute('ban_type');
            if (isset($grouped[$type])) {
                /** @var array<string, mixed> $tplArray */
                $tplArray = $tpl->toArray();
                $grouped[$type][] = $tplArray;
            }
        }

        return $grouped;
    }
}
