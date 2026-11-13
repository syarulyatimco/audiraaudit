<?php

namespace App\Models;

use App\Enums\ScanStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** File identity (path, hash, size) is immutable — enforced by a DB trigger. */
class DocumentVersion extends Model
{
    use BelongsToFirm;

    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'version_no', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'sha256', 'uploaded_by'];

    protected function casts(): array
    {
        return ['scan_status' => ScanStatus::class, 'scanned_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (DocumentVersion $v) {
            $v->uuid ??= (string) Str::uuid();
            $v->scan_status ??= ScanStatus::Pending;
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isDownloadable(): bool
    {
        return $this->scan_status === ScanStatus::Clean;
    }

    public function sizeLabel(): string
    {
        $b = $this->size_bytes;

        return $b >= 1048576 ? number_format($b / 1048576, 1).' MB' : number_format(max(1, $b / 1024)).' KB';
    }
}
