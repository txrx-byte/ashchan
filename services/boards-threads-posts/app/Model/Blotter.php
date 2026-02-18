<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property int    $id
 * @property string $created_at
 * @property string $content
 * @property bool   $is_important
 */
class Blotter extends Model
{
    protected ?string $table = 'blotter';

    public bool $timestamps = false;

    protected array $fillable = ['content', 'is_important'];

    protected array $casts = [
        'id'           => 'integer',
        'is_important' => 'boolean',
    ];
}
