<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

#[Fillable(['team_id', 'user_id', 'type', 'status', 'payload', 'results'])]
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    /**
     * Get the team this import belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who started the import.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Mark the import as actively processing.
     */
    public function markProcessing(): void
    {
        $this->status = ImportStatus::Processing;
        $this->started_at = Date::now();
        $this->save();
    }

    /**
     * Mark the import as completed.
     */
    public function markCompleted(): void
    {
        $this->status = ImportStatus::Completed;
        $this->completed_at = Date::now();
        $this->save();
    }

    /**
     * Mark the import as failed, recording the error.
     */
    public function markFailed(string $error): void
    {
        $this->status = ImportStatus::Failed;
        $this->error = $error;
        $this->failed_at = Date::now();
        $this->save();
    }

    /**
     * Record the step the import is currently running.
     */
    public function recordStep(string $step): void
    {
        $this->current_step = $step;
        $this->save();
    }

    /**
     * Merge a step's summary into the accumulated results.
     *
     * @param  array<string, mixed>  $data
     */
    public function mergeResults(array $data): void
    {
        $this->results = array_merge($this->results ?? [], $data);
        $this->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'payload' => 'array',
            'results' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
